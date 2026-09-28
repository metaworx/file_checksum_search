#!/usr/bin/env python3
"""
The app store, as a release sees it: before the upload through a fake store,
after it through the real store's listing.

  appstore.py fake  — serves the real store's full listing (api/v1/apps.json,
      the file every server reads) with only this app's entry changed: the
      release under test first, with its download URL, signature and
      channel. Every other request passes through to the real store, so a
      server pointed here with `appstoreurl` sees the store as it is, with
      one release more, and runs its real install path against it.

  appstore.py watch — after the upload: waits until a host of the store's
      listing names the release, compares the store's entry with the one the
      fake store serves, then waits until every host seen names it and
      reports how long each took. The store publishes no list of mirrors;
      the hosts are the ones its redirects lead to, plus itself. With
      --since, the upload's time, an entry of the version last modified
      before it is an earlier upload of the same version, still served
      from a cache or a mirror: the host is asked again, not compared.

Both build the release entry from the same arguments with the same code, so
"the store says the same" means the store says what the installs tested.

Usage:
  appstore.py fake  --version V --download URL --signature FILE [--nightly] [--port 8090]
  appstore.py watch --version V --download URL --signature FILE [--nightly]
                    --certificate FILE [--since TIME] [--first-timeout MIN]
                    [--all-timeout MIN] [--interval SEC] [--summary FILE]
                    [--result FILE]
"""

import argparse
import datetime
import gzip
import http.client
import http.server
import json
import re
import socketserver
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

APP_ID = 'file_checksum_search'
STORE = 'apps.nextcloud.com'
LISTING = '/api/v1/apps.json'

# The fields of a release entry the store derives from what it is given, and
# which the installs before the upload depend on.
COMPARED = (
	'version', 'isNightly', 'download', 'signature',
	'platformVersionSpec', 'rawPlatformVersionSpec', 'phpVersionSpec', 'rawPhpVersionSpec',
)

# How far the store's clock and the uploader's may differ before a new entry
# looks older than its upload.
CLOCK_MARGIN = datetime.timedelta(minutes=2)


# ---------------------------------------------------------------------------
# The release entry
# ---------------------------------------------------------------------------

def version_spec(minimum: str | None, maximum: str | None) -> tuple[str, str]:
	"""The store's two spellings of a range: normalised and as the manifest said it."""
	def full(v: str) -> str:
		parts = v.split('.')
		return '.'.join((parts + ['0', '0'])[:3])

	raw = ' '.join(filter(None, [f'>={minimum}' if minimum else '', f'<={maximum}' if maximum else '']))
	lower = f'>={full(minimum)}' if minimum else ''
	upper = f'<{int(maximum.split(".")[0]) + 1}.0.0' if maximum else ''
	return ' '.join(filter(None, [lower, upper])) or '*', raw or '*'


def expected_fields(args) -> dict:
	"""What the release's entry must say, from the manifest and the arguments."""
	manifest = ET.parse(args.manifest).getroot()
	nextcloud = manifest.find('dependencies/nextcloud')
	php = manifest.find('dependencies/php')

	platform, raw_platform = version_spec(nextcloud.get('min-version'), nextcloud.get('max-version'))
	php_spec, raw_php = version_spec(php.get('min-version') if php is not None else None, None)

	with open(args.signature, encoding='ascii') as f:
		signature = re.sub(r'\s', '', f.read())

	return {
		'version': args.version,
		'isNightly': args.nightly,
		'download': args.download,
		'signature': signature,
		'platformVersionSpec': platform,
		'rawPlatformVersionSpec': raw_platform,
		'phpVersionSpec': php_spec,
		'rawPhpVersionSpec': raw_php,
	}


def listed_release(apps: list, version: str, nightly: bool) -> tuple[dict | None, dict | None]:
	"""This app's entry, and its release of the version and channel, if listed."""
	entry = next((app for app in apps if app['id'] == APP_ID), None)
	if entry is None:
		return None, None
	release = next((r for r in entry['releases'] if r['version'] == version and r['isNightly'] == nightly), None)
	return entry, release


def utc(stamp: str) -> datetime.datetime:
	"""An ISO 8601 time; UTC where it names no zone."""
	moment = datetime.datetime.fromisoformat(stamp)
	return moment if moment.tzinfo else moment.replace(tzinfo=datetime.timezone.utc)


# ---------------------------------------------------------------------------
# fake
# ---------------------------------------------------------------------------

def fetch_following(path: str) -> tuple[int, str, bytes]:
	"""GET from the real store, following its redirects to the mirrors."""
	request = urllib.request.Request(f'https://{STORE}{path}', headers={'User-Agent': 'fcias-fake-store'})
	try:
		with urllib.request.urlopen(request, timeout=120) as response:
			return response.status, response.headers.get('Content-Type', 'application/octet-stream'), response.read()
	except urllib.error.HTTPError as e:
		return e.code, e.headers.get('Content-Type', 'text/plain'), e.read()


def fake(args) -> None:
	status, _, body = fetch_following(LISTING)
	if status != 200:
		sys.exit(f'the real store answered {status} for {LISTING}')

	apps = json.loads(body)
	entry = next((app for app in apps if app['id'] == APP_ID), None)
	if entry is None:
		sys.exit(f'{APP_ID} is not in the real store\'s listing, so there is no entry to extend')

	now = datetime.datetime.now(datetime.timezone.utc).isoformat()
	release = dict(entry['releases'][0] if entry['releases'] else {})
	release.update(expected_fields(args))
	release.update({'signatureDigest': 'sha512', 'created': now, 'lastModified': now})
	entry['releases'] = [release] + [r for r in entry['releases'] if r['version'] != args.version]
	listing = json.dumps(apps).encode()

	print(
		f'fake store: {len(apps)} apps from the real listing; {APP_ID} lists '
		f'{[r["version"] for r in entry["releases"]]}, {args.version} '
		f'{"nightly" if args.nightly else "stable"} from {args.download}',
		flush=True,
	)

	class Handler(http.server.BaseHTTPRequestHandler):
		def do_GET(self) -> None:
			if self.path.split('?')[0] == LISTING:
				code, content_type, payload = 200, 'application/json', listing
			else:
				code, content_type, payload = fetch_following(self.path)
			self.send_response(code)
			self.send_header('Content-Type', content_type)
			self.send_header('Content-Length', str(len(payload)))
			self.end_headers()
			self.wfile.write(payload)

		def log_message(self, fmt: str, *values) -> None:
			print('fake store: ' + fmt % values, flush=True)

	class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
		daemon_threads = True
		allow_reuse_address = True

	with Server(('127.0.0.1', args.port), Handler) as server:
		print(f'fake store: serving on http://127.0.0.1:{args.port}{LISTING}', flush=True)
		server.serve_forever()


# ---------------------------------------------------------------------------
# watch
# ---------------------------------------------------------------------------

def request(method: str, host: str, etag: str | None = None) -> tuple[int, dict, bytes]:
	"""One request to one host, redirects not followed, gzip accepted."""
	headers = {'User-Agent': 'fcias-release-watch', 'Accept-Encoding': 'gzip'}
	if etag:
		headers['If-None-Match'] = etag
	connection = http.client.HTTPSConnection(host, timeout=120)
	try:
		connection.request(method, LISTING, headers=headers)
		response = connection.getresponse()
		body = response.read()
		response_headers = {k.lower(): v for k, v in response.getheaders()}
	finally:
		connection.close()
	if response_headers.get('content-encoding') == 'gzip':
		body = gzip.decompress(body)
	return response.status, response_headers, body


def minutes(since: float) -> float:
	return round((time.time() - since) / 60, 1)


def watch(args) -> int:
	expected = expected_fields(args)
	with open(args.certificate, encoding='ascii') as f:
		certificate = re.sub(r'\s', '', f.read())

	start = time.time()
	hosts: set[str] = {STORE}
	etags: dict[str, str] = {}
	listed: dict[str, float] = {}
	compared = False
	mismatches: list[str] = []

	def log(message: str) -> None:
		print(f'[{minutes(start):6.1f} min] {message}', flush=True)

	while True:
		# Which hosts the store sends a request to. A HEAD is enough to see
		# a redirect, and several per round catch the spread.
		for _ in range(6):
			try:
				status, headers, _ = request('HEAD', STORE)
			except OSError as e:
				log(f'{STORE}: {e}')
				continue
			if status in (301, 302, 303, 307, 308) and headers.get('location'):
				host = urllib.parse.urlparse(headers['location']).hostname
				if host and host not in hosts:
					hosts.add(host)
					log(f'the store redirects to {host}')

		for host in sorted(hosts - set(listed)):
			try:
				status, headers, body = request('GET', host, etags.get(host))
			except OSError as e:
				log(f'{host}: {e}')
				continue
			if status == 304 or status in (301, 302, 303, 307, 308):
				continue
			if status != 200:
				log(f'{host}: HTTP {status}')
				continue
			etags[host] = headers.get('etag', '')
			entry, release = listed_release(json.loads(body), args.version, args.nightly)
			if release is None:
				log(f'{host}: listing changed ({headers.get("last-modified", "?")}), {args.version} not in it yet')
				continue
			if args.since and utc(release['lastModified']) < args.since - CLOCK_MARGIN:
				log(f'{host}: still lists the upload of {args.version} from {release["lastModified"]}')
				continue

			listed[host] = minutes(start)
			log(f'{host}: lists {args.version}')

			if not compared:
				compared = True
				for field in COMPARED:
					if release.get(field) != expected[field]:
						mismatches.append(f'{field}: the store says {release.get(field)!r}, the installs tested {expected[field]!r}')
				if re.sub(r'\s', '', entry.get('certificate', '')) != certificate:
					mismatches.append('certificate: the store\'s differs from the one the release was signed for')
				if mismatches:
					for line in mismatches:
						log('MISMATCH ' + line)
					break
				log('the store\'s entry says what the installs before the upload tested')

		if mismatches:
			break
		if not listed and minutes(start) >= args.first_timeout:
			break
		if listed and set(listed) >= hosts:
			break
		if minutes(start) >= args.all_timeout:
			break
		time.sleep(args.interval)

	converged = bool(listed) and set(listed) >= hosts and not mismatches
	result = {
		'version': args.version,
		'nightly': args.nightly,
		'hosts': {host: listed.get(host) for host in sorted(hosts)},
		'compared': compared and not mismatches,
		'mismatches': mismatches,
		'converged': converged,
		'minutes': minutes(start),
	}

	if args.result:
		with open(args.result, 'w', encoding='utf-8') as f:
			json.dump(result, f, indent=2)

	if args.summary:
		with open(args.summary, 'a', encoding='utf-8') as f:
			f.write(f'### {APP_ID} {args.version} in the store\'s listing\n\n')
			f.write('| Host | Lists it after |\n|---|---|\n')
			for host, after in result['hosts'].items():
				f.write(f'| `{host}` | {f"{after} min" if after is not None else "not within the watch"} |\n')
			if mismatches:
				f.write('\n**The store\'s entry differs from what the installs tested:**\n\n')
				f.write(''.join(f'- {line}\n' for line in mismatches))
			elif compared:
				f.write('\nThe store\'s entry says what the installs before the upload tested.\n')

	if mismatches:
		return 2
	if not listed:
		print(f'::error::No host of the store lists {args.version} after {args.first_timeout} minutes.', flush=True)
		return 1
	if not converged:
		print(f'::warning::Not every host lists {args.version} after {args.all_timeout} minutes.', flush=True)
	return 0


# ---------------------------------------------------------------------------

def main() -> None:
	parser = argparse.ArgumentParser()
	commands = parser.add_subparsers(dest='command', required=True)

	for name in ('fake', 'watch'):
		command = commands.add_parser(name)
		command.add_argument('--version', required=True)
		command.add_argument('--download', required=True)
		command.add_argument('--signature', required=True)
		command.add_argument('--nightly', action='store_true')
		command.add_argument('--manifest', default='appinfo/info.xml')

	commands.choices['fake'].add_argument('--port', type=int, default=8090)

	watcher = commands.choices['watch']
	watcher.add_argument('--certificate', required=True)
	watcher.add_argument('--since', type=utc, metavar='TIME', help='the upload\'s time, ISO 8601; UTC where it names no zone')
	watcher.add_argument('--first-timeout', type=float, default=30, metavar='MIN', help='minutes until a host must list the upload (default: %(default)s)')
	watcher.add_argument('--all-timeout', type=float, default=300, metavar='MIN', help='minutes until every host should list it (default: %(default)s)')
	watcher.add_argument('--interval', type=float, default=60, metavar='SEC', help='seconds between rounds (default: %(default)s)')
	watcher.add_argument('--summary')
	watcher.add_argument('--result')

	args = parser.parse_args()
	if args.command == 'fake':
		fake(args)
	else:
		sys.exit(watch(args))


if __name__ == '__main__':
	main()
