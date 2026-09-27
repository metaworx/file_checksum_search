#!/usr/bin/env python3
"""
A stand-in for the Nextcloud app store that lists a release before the real
store does.

It takes the real store's full listing (api/v1/apps.json, the file every
server reads), changes only this app's entry — the release under test goes
first in its release list, with its download URL, its signature and its
channel — and serves that. Every other request (categories, the AppAPI
list, Discover, anything else) is passed through to the real store, so a
server pointed here with `appstoreurl` sees the store as it is, with one
release more.

The server then runs its own install path against it: listing, download
from the URL given (a GitHub release asset), the certificate from the real
entry checked against Nextcloud's authority, the signature checked against
the tarball, the install steps.

Usage:
  fake-store.py --version 0.20.3 --download URL --signature FILE [--nightly]
                [--manifest appinfo/info.xml] [--port 8090]
"""

import argparse
import datetime
import http.server
import json
import re
import socketserver
import sys
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET

APP_ID = 'file_checksum_search'
UPSTREAM = 'https://apps.nextcloud.com'
LISTING = '/api/v1/apps.json'


def fetch(path: str) -> tuple[int, str, bytes]:
	"""GET from the real store, following its redirects to the mirrors."""
	request = urllib.request.Request(UPSTREAM + path, headers={'User-Agent': 'fcias-fake-store'})
	try:
		with urllib.request.urlopen(request, timeout=120) as response:
			return response.status, response.headers.get('Content-Type', 'application/octet-stream'), response.read()
	except urllib.error.HTTPError as e:
		return e.code, e.headers.get('Content-Type', 'text/plain'), e.read()


def version_spec(minimum: str | None, maximum: str | None) -> tuple[str, str]:
	"""The store's two spellings of a range: normalised and as the manifest said it."""
	def full(v: str) -> str:
		parts = v.split('.')
		return '.'.join((parts + ['0', '0'])[:3])

	raw = ' '.join(filter(None, [f'>={minimum}' if minimum else '', f'<={maximum}' if maximum else '']))
	lower = f'>={full(minimum)}' if minimum else ''
	upper = f'<{int(maximum.split(".")[0]) + 1}.0.0' if maximum else ''
	return ' '.join(filter(None, [lower, upper])) or '*', raw or '*'


def the_release(args, template: dict) -> dict:
	"""This release's entry, shaped like the store's and filled from the manifest."""
	manifest = ET.parse(args.manifest).getroot()
	nextcloud = manifest.find('dependencies/nextcloud')
	php = manifest.find('dependencies/php')

	platform, raw_platform = version_spec(nextcloud.get('min-version'), nextcloud.get('max-version'))
	php_spec, raw_php = version_spec(php.get('min-version') if php is not None else None, None)

	with open(args.signature, encoding='ascii') as f:
		signature = re.sub(r'\s', '', f.read())

	now = datetime.datetime.now(datetime.timezone.utc).isoformat()
	release = dict(template)
	release.update({
		'version': args.version,
		'download': args.download,
		'signature': signature,
		'signatureDigest': 'sha512',
		'isNightly': args.nightly,
		'platformVersionSpec': platform,
		'rawPlatformVersionSpec': raw_platform,
		'phpVersionSpec': php_spec,
		'rawPhpVersionSpec': raw_php,
		'created': now,
		'lastModified': now,
	})
	return release


def the_listing(args) -> bytes:
	status, _, body = fetch(LISTING)
	if status != 200:
		sys.exit(f'the real store answered {status} for {LISTING}')

	apps = json.loads(body)
	entry = next((app for app in apps if app['id'] == APP_ID), None)
	if entry is None:
		sys.exit(f'{APP_ID} is not in the real store\'s listing, so there is no entry to extend')

	release = the_release(args, entry['releases'][0] if entry['releases'] else {})
	entry['releases'] = [release] + [r for r in entry['releases'] if r['version'] != args.version]

	print(
		f'fake store: {len(apps)} apps from the real listing; {APP_ID} lists '
		f'{[r["version"] for r in entry["releases"]]}, {args.version} '
		f'{"nightly" if args.nightly else "stable"} from {args.download}',
		flush=True,
	)
	return json.dumps(apps).encode()


def main() -> None:
	parser = argparse.ArgumentParser()
	parser.add_argument('--version', required=True)
	parser.add_argument('--download', required=True)
	parser.add_argument('--signature', required=True)
	parser.add_argument('--nightly', action='store_true')
	parser.add_argument('--manifest', default='appinfo/info.xml')
	parser.add_argument('--port', type=int, default=8090)
	args = parser.parse_args()

	listing = the_listing(args)

	class Handler(http.server.BaseHTTPRequestHandler):
		def do_GET(self) -> None:
			if self.path.split('?')[0] == LISTING:
				status, content_type, body = 200, 'application/json', listing
			else:
				status, content_type, body = fetch(self.path)
			self.send_response(status)
			self.send_header('Content-Type', content_type)
			self.send_header('Content-Length', str(len(body)))
			self.end_headers()
			self.wfile.write(body)

		def log_message(self, fmt: str, *values) -> None:
			print('fake store: ' + fmt % values, flush=True)

	class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
		daemon_threads = True
		allow_reuse_address = True

	with Server(('127.0.0.1', args.port), Handler) as server:
		print(f'fake store: serving on http://127.0.0.1:{args.port}{LISTING}', flush=True)
		server.serve_forever()


if __name__ == '__main__':
	main()
