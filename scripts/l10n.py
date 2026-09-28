#!/usr/bin/env python3
"""
The app's translations: the texts wrapped in t() and n() become a template,
the template is merged into one .po file per language, and the .po files
become the l10n/ files Nextcloud loads. The work is done by Nextcloud's own
translation tool, pinned below, the one its Transifex sync runs; this script
feeds it a clean copy of the app and keeps its output stable in git.

  l10n.py pot        — extract the texts into the template,
                       translationfiles/templates/file_checksum_search.pot
  l10n.py update     — pot, then merge the template into every
                       translationfiles/<lang>/file_checksum_search.po
  l10n.py add LANG   — start a language: a .po from the template
  l10n.py build      — convert the .po files into l10n/<lang>.js and .json
  l10n.py check      — fail if the template, a .po file or l10n/ is not what
                       the three commands above would write, or a language
                       has a text untranslated or marked fuzzy

Every language kept here is kept complete: check fails on a missing
translation, and on a fuzzy one msgmerge guessed from a changed text.

The committed files carry no source locations, and the texts are sorted, so a
diff shows texts that changed and nothing else. scripts/l10n.sh, and a
scripts/l10n.ps1 once there is one, are the doors to this script. It needs PHP, and gettext's xgettext, msgmerge, msgcat,
msginit, msgattrib and msgfmt, on the path.
"""

import argparse
import filecmp
import hashlib
import os
import re
import shutil
import subprocess
import sys
import tempfile
import urllib.request
from pathlib import Path

APP_ID = 'file_checksum_search'
ROOT = Path(__file__).resolve().parent.parent
DOOR = 'scripts/l10n.sh'
TRANSLATIONS = ROOT / 'translationfiles'
TEMPLATE = TRANSLATIONS / 'templates' / f'{APP_ID}.pot'
L10N = ROOT / 'l10n'

# Nextcloud's translation tool, at the last commit that changed it, checked
# by its SHA-256 before every use.
TOOL_COMMIT = '5ed96fa6f9535415f9da1e672f38d7f7c659b2ee'
TOOL_SHA256 = '667ca0a3d7ca8858f66f72007792d5bbab867dba6b1bb458d95ec7a74f17385f'
TOOL_URL = f'https://raw.githubusercontent.com/nextcloud/docker-ci/{TOOL_COMMIT}/translations/translationtool/translationtool.phar'
TOOL = ROOT / 'build' / 'l10n' / f'translationtool-{TOOL_COMMIT[:10]}.phar'

# What the tool writes above a text it found in a Vue template: the file and
# line, which move with every edit, alone or after the comment a developer
# left for translators (`<!-- TRANSLATORS … -->`). The comment is kept.
VUE_HINT = re.compile(r'^#\. TRANSLATORS \S+\.vue:\d+\n', re.M)
VUE_HINT_SUFFIX = re.compile(r'^(#\. TRANSLATORS .*?) \(\S+\.vue:\d+\)$', re.M)


def run(*command: str, cwd: Path | None = None, quiet: bool = False) -> str:
	"""Runs a command and returns its output; its failure ends the script with that output."""
	result = subprocess.run(command, cwd=cwd, capture_output=True, text=True)
	if result.returncode != 0:
		sys.exit(f'{" ".join(command)} failed:\n{result.stdout}{result.stderr}')
	if not quiet and result.stderr.strip():
		print(result.stderr.strip(), file=sys.stderr)
	return result.stdout


def tool() -> Path:
	"""The pinned translation tool, downloaded once and checked every time."""
	if not TOOL.exists():
		TOOL.parent.mkdir(parents=True, exist_ok=True)
		with urllib.request.urlopen(TOOL_URL, timeout=120) as response:
			TOOL.write_bytes(response.read())
	digest = hashlib.sha256(TOOL.read_bytes()).hexdigest()
	if digest != TOOL_SHA256:
		TOOL.unlink()
		sys.exit(f'{TOOL.name}: SHA-256 {digest}, expected {TOOL_SHA256}; the download was removed')
	return TOOL


def app_copy(work: Path) -> Path:
	"""A copy of the app's files as git sees them, tracked or new, ignored ones left out.

	The tool takes every directory named l10n below its working directory
	for an app, and writes its helper files into the app while it runs; a
	copy keeps the working tree, vendor/ and node_modules/ out of its way.
	The copy's directory is named after the app, as the tool expects.
	"""
	app = work / APP_ID
	listed = run('git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard', cwd=ROOT)
	for name in filter(None, listed.split('\0')):
		source = ROOT / name
		if source.is_file():
			target = app / name
			target.parent.mkdir(parents=True, exist_ok=True)
			shutil.copy2(source, target)
	(app / 'l10n').mkdir(exist_ok=True)
	return app


def normalised(pot_or_po: Path) -> str:
	"""The file as it is committed: no locations, no Vue hints, no creation date, sorted by text."""
	text = run('msgcat', '--no-location', '--sort-output', '--no-wrap', str(pot_or_po))
	text = VUE_HINT_SUFFIX.sub(r'\1', VUE_HINT.sub('', text))
	return re.sub(r'^"POT-Creation-Date: [^"]*"\n', '', text, flags=re.M)


def template(work: Path) -> str:
	"""The template the tool extracts from the app, as committed."""
	app = app_copy(work)
	run('php', str(tool()), 'create-pot-files', cwd=app, quiet=True)
	return normalised(app / 'translationfiles' / 'templates' / f'{APP_ID}.pot')


def languages() -> list[str]:
	return sorted(p.name for p in TRANSLATIONS.iterdir() if p.is_dir() and p.name != 'templates') if TRANSLATIONS.is_dir() else []


def po_file(language: str) -> Path:
	return TRANSLATIONS / language / f'{APP_ID}.po'


def merged(po: Path, pot: Path, work: Path) -> str:
	"""The .po file merged with the template: new texts added, fuzzy guesses marked, dropped texts removed."""
	merged_po, kept = work / f'{po.parent.name}.merged.po', work / f'{po.parent.name}.po'
	run('msgmerge', '--quiet', '--no-location', '--no-wrap', '--sort-output', '--output-file', str(merged_po), str(po), str(pot))
	run('msgattrib', '--no-obsolete', '--no-location', '--no-wrap', '--sort-output', '--output-file', str(kept), str(merged_po))
	return normalised(kept)


def built(work: Path) -> dict[str, bytes]:
	"""The l10n/ files the tool converts the .po files into, by name; the copy of the app carries them."""
	app = app_copy(work)
	run('php', str(tool()), 'convert-po-files', cwd=app, quiet=True)
	return {p.name: p.read_bytes() for p in sorted((app / 'l10n').glob('*.js*'))}


def statistics(po: Path) -> tuple[int, int]:
	"""How many texts the .po file leaves untranslated, and how many are fuzzy."""
	result = subprocess.run(['msgfmt', '--check', '--statistics', '--output-file', os.devnull, str(po)], capture_output=True, text=True)
	if result.returncode != 0:
		sys.exit(f'{po.relative_to(ROOT)}: {result.stderr.strip()}')
	untranslated = re.search(r'(\d+) untranslated', result.stderr)
	fuzzy = re.search(r'(\d+) fuzzy', result.stderr)
	return int(untranslated[1]) if untranslated else 0, int(fuzzy[1]) if fuzzy else 0


# ---------------------------------------------------------------------------

def pot() -> None:
	with tempfile.TemporaryDirectory() as work:
		TEMPLATE.parent.mkdir(parents=True, exist_ok=True)
		TEMPLATE.write_text(template(Path(work)), encoding='utf-8')
	print(f'{TEMPLATE.relative_to(ROOT)}: {count(TEMPLATE)} texts')


def update() -> None:
	pot()
	with tempfile.TemporaryDirectory() as work:
		for language in languages():
			po = po_file(language)
			po.write_text(merged(po, TEMPLATE, Path(work)), encoding='utf-8')
			untranslated, fuzzy = statistics(po)
			print(f'{po.relative_to(ROOT)}: {untranslated} untranslated, {fuzzy} fuzzy')


def add(language: str) -> None:
	if not re.fullmatch(r'[a-z]{2,3}(_[A-Z]{2}|_[A-Za-z]+)?', language):
		sys.exit(f'{language}: not a language code such as de or de_DE')
	po = po_file(language)
	if po.exists():
		sys.exit(f'{po.relative_to(ROOT)} exists; update merges new texts into it')
	pot()
	po.parent.mkdir(parents=True, exist_ok=True)
	run('msginit', '--no-translator', '--no-wrap', '--locale', f'{language}.UTF-8', '--input', str(TEMPLATE), '--output-file', str(po), quiet=True)
	with tempfile.TemporaryDirectory() as work:
		po.write_text(merged(po, TEMPLATE, Path(work)), encoding='utf-8')
	print(f'{po.relative_to(ROOT)}: {count(po)} texts to translate')


def build() -> None:
	with tempfile.TemporaryDirectory() as work:
		files = built(Path(work))
	L10N.mkdir(exist_ok=True)
	for stale in L10N.glob('*.js*'):
		if stale.name not in files:
			stale.unlink()
	for name, content in files.items():
		(L10N / name).write_bytes(content)
	print(f'l10n/: {", ".join(files) or "no translated language"}')


def check() -> int:
	problems: list[str] = []
	with tempfile.TemporaryDirectory() as work:
		work = Path(work)
		(work / 'template').mkdir()
		current = template(work / 'template')
		if not TEMPLATE.exists() or TEMPLATE.read_text(encoding='utf-8') != current:
			problems.append(f'{TEMPLATE.relative_to(ROOT)} is not what the source says: run {DOOR} update')
		fresh = work / 'current.pot'
		fresh.write_text(current, encoding='utf-8')

		for language in languages():
			po = po_file(language)
			if po.read_text(encoding='utf-8') != merged(po, fresh, work):
				problems.append(f'{po.relative_to(ROOT)} is not what update writes (a text added or dropped in the source, or the file\'s form): run {DOOR} update')
			untranslated, fuzzy = statistics(po)
			if untranslated or fuzzy:
				problems.append(f'{po.relative_to(ROOT)}: {untranslated} untranslated, {fuzzy} fuzzy')

		(work / 'build').mkdir()
		files = built(work / 'build')
		committed = {p.name: p.read_bytes() for p in sorted(L10N.glob('*.js*'))} if L10N.is_dir() else {}
		if files != committed:
			problems.append(f'l10n/ is not what the .po files convert to: run {DOOR} build')

	for problem in problems:
		print(problem, file=sys.stderr)
	if not problems:
		print(f'translations current: {count(TEMPLATE)} texts; {", ".join(languages()) or "no language yet"}')
	return 1 if problems else 0


def count(pot_or_po: Path) -> int:
	"""The texts in a template or .po file, the header not counted."""
	return len(re.findall(r'^msgid ', pot_or_po.read_text(encoding='utf-8'), re.M)) - 1


def main() -> None:
	parser = argparse.ArgumentParser(description='The app\'s translations, kept with Nextcloud\'s translation tool.')
	commands = parser.add_subparsers(dest='command', required=True)
	commands.add_parser('pot', help='extract the texts into the template')
	commands.add_parser('update', help='pot, then merge the template into every language')
	commands.add_parser('add', help='start a language from the template').add_argument('language', help='e.g. de or de_DE')
	commands.add_parser('build', help='convert the .po files into l10n/')
	commands.add_parser('check', help='fail if anything above is out of date or a language is incomplete')
	args = parser.parse_args()

	if args.command == 'pot':
		pot()
	elif args.command == 'update':
		update()
	elif args.command == 'add':
		add(args.language)
	elif args.command == 'build':
		build()
	else:
		sys.exit(check())


if __name__ == '__main__':
	main()
