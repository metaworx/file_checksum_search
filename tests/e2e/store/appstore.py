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

  appstore.py watch — after the upload, compares the store's entry with the
      one the fake store serves, wherever the store shows it:
      - by default, the store's releases page, rendered from its database,
        for every Nextcloud version the manifest allows: the release is
        published. The JSON listings are cached, and lag;
      - with --mirrors, api/v1/apps.json on the store and on every host its
        redirects lead to, name, summary and description included: clients
        can update, wherever the store sends them. The store publishes no
        list of mirrors.
      Every round prints what each host or version still to check shows; the
      last line, `watch-result {...}`, is the outcome as JSON. With --since,
      the upload's time, an entry of the version from before it is an
      earlier upload of the same version, still served from a cache or a
      mirror: it is asked for again, not compared; and the minutes reported
      count from the upload. With --comment, a watch that succeeds says so on
      the commit the run is for, mentioning whom it names; a failed run
      needs no comment, GitHub mails about it.

Both build the release entry from the same arguments with the same code, so
"the store says the same" means the store says what the installs tested.

Usage:
  appstore.py fake  --version V --download URL --signature FILE [--nightly] [--port 8090]
  appstore.py watch --version V --download URL --signature FILE [--nightly]
                    --certificate FILE [--since TIME] [--mirrors]
                    [--timeout MIN] [--interval SEC] [--summary FILE]
                    [--comment MENTION]
"""

import argparse
import datetime
import gzip
import html
import http.client
import http.server
import json
import os
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
RELEASES_PAGE = f'/apps/{APP_ID}/releases'
REDIRECTS = (301, 302, 303, 307, 308)


def _bare(value) -> str:
	return re.sub(r'\s', '', str(value))


def _squeezed(value) -> str:
	return ' '.join(str(value).split())


def _range(value) -> str:
	return ' '.join(str(value).replace(',', ' ').split())


# The fields compared after the upload, each normalised as its source may
# write it: the releases page separates a range's bounds with a comma and
# breaks the certificate into lines, and the store trims the texts. A source
# compares the fields it shows; the releases page shows no texts.
COMPARED = {
	'version': str,
	'isNightly': bool,
	'download': str,
	'signature': _bare,
	'certificate': _bare,
	'platformVersionSpec': _range,
	'rawPlatformVersionSpec': _range,
	'phpVersionSpec': _range,
	'rawPhpVersionSpec': _range,
	'name': _squeezed,
	'summary': _squeezed,
	'description': _squeezed,
}

# How the fields compared are named in the log and the summary.
LABELS = {
	'version': 'version', 'isNightly': 'channel', 'download': 'download', 'signature': 'signature',
	'certificate': 'certificate', 'platformVersionSpec': 'Nextcloud range', 'rawPlatformVersionSpec': 'Nextcloud range',
	'phpVersionSpec': 'PHP range', 'rawPhpVersionSpec': 'PHP range',
	'name': 'name', 'summary': 'summary', 'description': 'description',
}

# The fields the releases page shows.
PAGE_FIELDS = ('version', 'isNightly', 'download', 'signature', 'certificate', 'platformVersionSpec', 'phpVersionSpec')

# The releases page's months, as Django writes them: 'Sept. 28, 2026, 7:50 a.m.'
AP_MONTHS = {
	'Jan.': 1, 'Feb.': 2, 'March': 3, 'April': 4, 'May': 5, 'June': 6,
	'July': 7, 'Aug.': 8, 'Sept.': 9, 'Oct.': 10, 'Nov.': 11, 'Dec.': 12,
}

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


def manifest_texts(manifest_path: str) -> dict:
	"""The app's name, summary and description, as the manifest gives them in English."""
	manifest = ET.parse(manifest_path).getroot()
	texts = {}
	for field in ('name', 'summary', 'description'):
		element = next((e for e in manifest.findall(field) if e.get('lang') in (None, 'en')), None)
		if element is not None:
			texts[field] = element.text or ''
	return texts


def platforms(manifest_path: str) -> list[int]:
	"""The Nextcloud major versions the manifest allows; only the minimum where it names no maximum."""
	nextcloud = ET.parse(manifest_path).getroot().find('dependencies/nextcloud')
	low = int(nextcloud.get('min-version').split('.')[0])
	high = int((nextcloud.get('max-version') or str(low)).split('.')[0])
	return list(range(low, high + 1))


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


def discover(hosts: set[str], log, tries: int) -> set[str]:
	"""Adds the hosts the store redirects to, and returns the new ones.

	A HEAD is enough to see a redirect, and several per round catch the
	spread: with three hosts, 20 miss one about once in 3,000 scans, 6 about
	once in eleven.
	"""
	new: set[str] = set()
	for _ in range(tries):
		try:
			status, headers, _ = request('HEAD', STORE)
		except OSError as e:
			log(f'{STORE}: {e}')
			continue
		if status in REDIRECTS and headers.get('location'):
			host = urllib.parse.urlparse(headers['location']).hostname
			if host and host not in hosts:
				hosts.add(host)
				new.add(host)
	return new


def this_upload(host: str, etags: dict[str, str], seen: dict[str, str], args) -> tuple[dict | None, str, str | None]:
	"""The host's entry for this upload when it lists it, what it answered, and where it redirected to.

	The ETag makes an unchanged listing a 304, which costs nothing; `seen`
	keeps what the host's listing said when it last changed.
	"""
	try:
		status, headers, body = request('GET', host, etags.get(host))
	except OSError as e:
		return None, f'no answer: {e}', None
	if status in REDIRECTS:
		target = urllib.parse.urlparse(headers.get('location', '')).hostname
		return None, f'redirected to {target or "?"}, its own copy not seen this round', target
	if status == 304:
		return None, f'{seen.get(host, "?")}, unchanged', None
	if status != 200:
		return None, f'HTTP {status}', None
	etags[host] = headers.get('etag', '')
	entry, release = listed_release(json.loads(body), args.version, args.nightly)
	if release is None:
		seen[host] = f'{args.version} not listed (listing of {headers.get("last-modified", "?")})'
		return None, seen[host], None
	if args.since and utc(release['lastModified']) < args.since - CLOCK_MARGIN:
		seen[host] = f'still the upload of {args.version} from {release["lastModified"]}'
		return None, seen[host], None
	# The release's fields, with the app's certificate and English texts.
	fields = {**release, 'certificate': entry.get('certificate', ''), **entry.get('translations', {}).get('en', {})}
	return fields, f'lists this upload, modified {release["lastModified"]}', None


def page_time(text: str) -> datetime.datetime:
	"""The releases page's time, 'Sept. 28, 2026, 7:50 a.m.', in UTC and to the minute."""
	date = re.fullmatch(r'(\S+) (\d+), (\d{4}), (.+)', text)
	if not date or date[1] not in AP_MONTHS:
		raise ValueError(text)
	if date[4] in ('midnight', 'noon'):
		hour, minute = (0 if date[4] == 'midnight' else 12), 0
	else:
		clock = re.fullmatch(r'(\d+)(?::(\d+))? ([ap])\.m\.', date[4])
		if not clock:
			raise ValueError(text)
		hour, minute = int(clock[1]) % 12 + (12 if clock[3] == 'p' else 0), int(clock[2] or 0)
	return datetime.datetime(int(date[3]), AP_MONTHS[date[1]], int(date[2]), hour, minute, tzinfo=datetime.timezone.utc)


def releases_page() -> tuple[dict[int, list[dict]] | None, str]:
	"""This app's releases on the store's releases page, per Nextcloud version.

	The page is rendered from the store's database, not from a cached
	listing, so it shows an upload at once. It is HTML, read by what it
	shows: an anchor per Nextcloud version, a title per release, its details
	as labelled table rows, and the download link. A field the page no
	longer shows reads as None, and fails the comparison.
	"""
	request_ = urllib.request.Request(
		f'https://{STORE}{RELEASES_PAGE}',
		headers={'User-Agent': 'fcias-release-watch', 'Accept-Language': 'en'},
	)
	try:
		with urllib.request.urlopen(request_, timeout=120) as response:
			page = response.read().decode('utf-8')
	except OSError as e:
		return None, f'no answer: {e}'

	sections: dict[int, list[dict]] = {}
	for platform, section in re.findall(r'<a name="(\d+)"></a>(.*?)(?=<a name="\d+"></a>|<footer)', page, re.S):
		releases = sections.setdefault(int(platform), [])
		for title, body in re.findall(r'<h5>(.*?)</h5>(.*?)(?=<h5>|\Z)', section, re.S):
			heading = re.search(r'(\S+)(?: \((nightly)\))?$', html.unescape(title).strip())
			rows = {
				html.unescape(label).strip(): ' '.join(html.unescape(re.sub(r'<[^>]+>', ' ', value)).split())
				for label, value in re.findall(r'<tr>\s*<td>([^<]*)</td>\s*<td[^>]*>(.*?)</td>\s*</tr>', body, re.S)
			}
			link = re.search(r'<a href="([^"]+)"[^>]*class="release-download"', body)
			releases.append({
				'version': heading[1] if heading else None,
				'isNightly': bool(heading and heading[2]),
				'download': html.unescape(link[1]) if link else None,
				'signature': rows.get('Signature'),
				'certificate': rows.get('Certificate'),
				'platformVersionSpec': rows.get('Required Nextcloud versions'),
				'phpVersionSpec': rows.get('PHP'),
				'updated': rows.get('Updated'),
			})
	return sections, 'read'


def page_upload(sections: dict[int, list[dict]], platform: int, args) -> tuple[dict | None, str]:
	"""The page's entry for this upload under one Nextcloud version, and what the page shows there."""
	if platform not in sections:
		return None, 'the page has no section for this Nextcloud version'
	release = next((r for r in sections[platform] if r['version'] == args.version and r['isNightly'] == args.nightly), None)
	if release is None:
		return None, f'{args.version} not listed'
	try:
		updated = page_time(release['updated'] or '')
	except ValueError:
		return None, f'the update time {release["updated"]!r} cannot be read'
	if args.since and updated < args.since - CLOCK_MARGIN:
		return None, f'still the upload of {args.version} from {updated:%Y-%m-%d %H:%M}Z'
	return {k: v for k, v in release.items() if k != 'updated'}, f'lists this upload, updated {updated:%H:%M}Z'


def described(fields) -> str:
	"""The fields compared, by name: 'version, channel, … and description'."""
	labels = list(dict.fromkeys(LABELS[field] for field in fields))
	return f'{", ".join(labels[:-1])} and {labels[-1]}' if len(labels) > 1 else ''.join(labels)


def shorten(value) -> str:
	text = repr(value)
	return text if len(text) <= 80 else f'{text[:56]}…{text[-16:]}'


def differences(actual: dict, expected: dict) -> list[str]:
	"""Where an entry differs from the upload, in the fields its source shows."""
	return [
		f'{field}: the store says {shorten(actual[field])}, the upload {shorten(expected[field])}'
		for field, normal in COMPARED.items()
		if field in actual and field in expected and normal(actual[field]) != normal(expected[field])
	]


def post_comment(text: str) -> None:
	"""Comments on the commit the GitHub run is for; its mentions are mailed."""
	repository, sha = os.environ['GITHUB_REPOSITORY'], os.environ['GITHUB_SHA']
	api = os.environ.get('GITHUB_API_URL', 'https://api.github.com')
	token = os.environ.get('GH_TOKEN') or os.environ['GITHUB_TOKEN']
	request_ = urllib.request.Request(
		f'{api}/repos/{repository}/commits/{sha}/comments',
		data=json.dumps({'body': text}).encode(),
		method='POST',
		headers={
			'Authorization': f'Bearer {token}',
			'Accept': 'application/vnd.github+json',
			'User-Agent': 'fcias-release-watch',
		},
	)
	with urllib.request.urlopen(request_, timeout=60):
		pass


def zulu(moment: datetime.datetime) -> str:
	return f'{moment:%Y-%m-%dT%H:%M:%S}Z'


def watch(args) -> int:
	expected = {**expected_fields(args), **manifest_texts(args.manifest)}
	with open(args.certificate, encoding='ascii') as f:
		expected['certificate'] = f.read()

	start = time.time()
	# The minutes reported count from the upload where it is known: that is
	# how long clients waited, and it makes two watches' numbers comparable.
	origin = args.since.timestamp() if args.since else start
	counted_from = f'the upload, {args.since:%H:%M:%S}Z' if args.since else 'the start of the watch'

	def now() -> datetime.datetime:
		return datetime.datetime.now(datetime.timezone.utc)

	def minutes() -> float:
		return round((time.time() - origin) / 60, 1)

	def log(message: str) -> None:
		print(f'[{now():%H:%M:%S}Z {minutes():6.1f} min] {message}', flush=True)

	# Without --mirrors, the Nextcloud versions on the store's releases page;
	# with it, the store and every host it redirects to.
	targets: set[str] = {STORE} if args.mirrors else {f'Nextcloud {p}' for p in platforms(args.manifest)}
	compared = described(f for f in COMPARED if f in expected and (args.mirrors or f in PAGE_FIELDS))
	etags: dict[str, str] = {}
	seen: dict[str, str] = {}
	states: dict[str, str] = {}
	listed: dict[str, float] = {}
	landed: dict[str, datetime.datetime] = {}
	mismatches: list[str] = []

	def settle(name: str, found: dict | None, state: str) -> bool:
		"""Logs what a host or version shows, and compares it; False on a difference."""
		states[name] = state
		log(f'{name}: {state}')
		if found is None:
			return True
		listed[name], landed[name] = minutes(), now()
		mismatches.extend(f'{name}: {line}' for line in differences(found, expected))
		if mismatches:
			states[name] = 'differs from the upload'
			for line in mismatches:
				log('MISMATCH ' + line)
			return False
		states[name] = 'matches the upload'
		log(f'{name}: {states[name]}')
		return True

	first = True
	while True:
		# Every host or version still to check, every round: what each one
		# shows is the progress. One that matched is done and drops out.
		if args.mirrors:
			new = discover(targets, log, 20 if first else 6)
			if first:
				log(f'minutes count from {counted_from}; the store and the hosts it redirects to: {", ".join(sorted(targets))}')
				log(f'compared with the upload: {compared}')
			else:
				for host in sorted(new):
					log(f'the store now also redirects to {host}')
			for host in sorted(targets - set(listed)):
				found, state, target = this_upload(host, etags, seen, args)
				if not settle(host, found, state):
					break
				if target and target not in targets:
					targets.add(target)
					log(f'the store now also redirects to {target}')
		else:
			if first:
				log(f'minutes count from {counted_from}; the store\'s releases page, for {", ".join(sorted(targets))}')
				log(f'compared with the upload: {compared}')
			sections, answer = releases_page()
			for name in sorted(targets - set(listed)):
				found, state = page_upload(sections, int(name.split()[-1]), args) if sections is not None else (None, answer)
				if not settle(name, found, state):
					break
		first = False

		done = set(listed) >= targets and not mismatches
		if mismatches or done or time.time() - start >= args.timeout * 60:
			break
		time.sleep(args.interval)

	channel = 'nightly' if args.nightly else 'stable'
	scope = 'every host of the store' if args.mirrors else 'the store\'s releases page'
	kind = 'Host' if args.mirrors else 'Nextcloud'

	if args.summary:
		with open(args.summary, 'a', encoding='utf-8') as f:
			f.write(f'### {APP_ID} {args.version} ({channel}): {scope}\n\n')
			f.write(f'| {kind} | Lists this upload after | Landed |\n|---|---|---|\n')
			for name in sorted(targets):
				after = f'{listed[name]} min' if name in listed else '—'
				f.write(f'| `{name}` | {after} | {zulu(landed[name]) if name in landed else states.get(name, "not asked")} |\n')
			f.write(f'\nMinutes count from {counted_from}.\n')
			if mismatches:
				f.write('\n**An entry differs from the upload:**\n\n')
				f.write(''.join(f'- {line}\n' for line in mismatches))
			elif done:
				f.write(f'\nEverywhere, the entry matches the upload: {compared}.\n')

	if mismatches:
		code = 2
	elif not done:
		code = 1
		missing = ', '.join(sorted(targets - set(listed)))
		print(f'::error::After {args.timeout:g} minutes, {missing} still do not show this upload of {args.version}.', flush=True)
	else:
		code = 0

	if code == 0 and args.comment:
		tag = os.environ.get('GITHUB_REF_NAME', args.version)
		repository = f'{os.environ.get("GITHUB_SERVER_URL", "https://github.com")}/{os.environ.get("GITHUB_REPOSITORY", "")}'
		run = f'{repository}/actions/runs/{os.environ.get("GITHUB_RUN_ID", "")}'
		where = 'every host the store sent requests to' if args.mirrors else 'the store\'s releases page'
		times = ''.join(f'- `{name}` after {listed[name]} min\n' for name in sorted(listed))
		text = (
			f'**{tag} ({channel})** is listed by {where}:\n\n{times}\n'
			f'[The run]({run})  \n[The release]({repository}/releases/tag/{tag})  \n{args.comment}'
		)
		try:
			post_comment(text)
			log('commented on the release commit: ' + text)
		except (OSError, KeyError) as e:
			print(f'::warning::The comment on the release commit failed: {e}', flush=True)

	# The outcome in one line, for grep: `grep -o 'watch-result .*'`.
	result = {
		'watch': 'mirrors' if args.mirrors else 'releases-page',
		'version': args.version,
		'channel': channel,
		'since': zulu(args.since) if args.since else None,
		'outcome': {0: 'ok', 1: 'timeout', 2: 'mismatch'}[code],
		'targets': {
			name: {
				'state': states.get(name, 'not asked'),
				'landed': zulu(landed[name]) if name in landed else None,
				'after_min': listed.get(name),
			}
			for name in sorted(targets)
		},
		'mismatches': mismatches,
	}
	print('watch-result ' + json.dumps(result), flush=True)
	return code


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
	watcher.add_argument('--mirrors', action='store_true', help='read apps.json on the store and every host it redirects to, instead of the store\'s releases page')
	watcher.add_argument('--timeout', type=float, default=30, metavar='MIN', help='minutes to wait (default: %(default)s)')
	watcher.add_argument('--interval', type=float, default=60, metavar='SEC', help='seconds between rounds (default: %(default)s)')
	watcher.add_argument('--summary', metavar='FILE', help='a Markdown file to append the outcome to, e.g. $GITHUB_STEP_SUMMARY')
	watcher.add_argument('--comment', metavar='MENTION', help='on success, comment on the run\'s commit, mentioning MENTION')

	args = parser.parse_args()
	if args.command == 'fake':
		fake(args)
	else:
		sys.exit(watch(args))


if __name__ == '__main__':
	main()
