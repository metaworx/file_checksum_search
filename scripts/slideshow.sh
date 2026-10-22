#!/usr/bin/env bash
#
# slideshow.sh - the app store's slideshow, from the listing's screenshots
#
# What a caller needs is in --help. What is left here is for whoever edits it.
#
# The frames are whole and opaque on purpose. The first slideshow was made by
# hand and optimised into patches with transparent pixels, and a viewer drawing
# a patch over the frame before let that frame show through. A frame that
# covers the canvas and has no transparent colour leaves nothing of another to
# show, however a viewer disposes of it; the run checks that of what it wrote,
# and replaces the target only then.

# Read by cli-v1.sh, which is sourced below and so is invisible to shellcheck.
# shellcheck disable=SC2034
SCRIPT_LONG="Store Slideshow"
# shellcheck disable=SC2034
SCRIPT_DESC='Builds the animated GIF the app store shows first, from the PNG screenshots
appinfo/info.xml lists after it, in that order.

Each is shrunk, never enlarged, to fit 940 by 700 pixels and centred on a
960 by 720 canvas of a light background, for 3.5 seconds, looping. Every frame
is whole and opaque, so no page shows through the next; the target is replaced
only once that holds.'

# shellcheck source-path=SCRIPTDIR source=../GUIDELINES/shared/lang/shell/tools/cli-v1.sh
. "$(git rev-parse --show-toplevel)/GUIDELINES/shared/lang/shell/tools/cli-v1.sh"
require_lib_version cli-v1.sh 1.8.0

declare_script_version 1.0.0
strict_mode
script_header

# ---------- Usage ----------
usage() {
	local -ri exit_code="${1:-$EXIT_USAGE}"
	local -ri show_flags="${2:-0}"

	usage_header "$show_flags" && cat <<EOF
  $SCRIPT_NAME [OPTIONS] <out.gif>
EOF

	usage_section_options "$show_flags" && usage_section_standard_cli

	usage_section_positional "$show_flags" && cat <<EOF
  <out.gif>  the slideshow to write; the store shows
             docs/Screenshots/Slideshow.gif
EOF

	usage_section_examples "$show_flags" && cat <<EOF
  $SCRIPT_NAME docs/Screenshots/Slideshow.gif
EOF

	usage_section_files "$show_flags" && cat <<EOF
  appinfo/info.xml        the screenshots, in the store's order
  docs/Screenshots/*.png  the frames, as tests/e2e/screenshots.cy.js takes them
EOF

	usage_section_requirements "$show_flags" && cat <<EOF
  ImageMagick: convert, identify
EOF

	usage_section_if "$show_flags" "$USAGE_SHOW_OPTIONS" "EXIT CODES" && cat <<EOF
  0  the slideshow is written, or already held exactly this
  1  a frame came out not whole or not opaque; the target is left as it was
  2  the tool could not run
EOF

	usage_end "$show_flags" "$exit_code"
}
# Usage on a bare invocation, though there is one obvious action: it writes a
# file, which the caller names (CLI.md 1.2).
(( $# == 0 )) && usage "$EXIT_USAGE"

# The first slideshow's light background, which its palette dithered.
readonly BACKGROUND='#eff4f8'

# shellcheck disable=SC2034
declare -A SHORTOPTS LONGOPTS
cli_getopt_declare SHORTOPTS LONGOPTS

PARSED="$(cli_getopt_parse SHORTOPTS LONGOPTS "$@")" || exit $?
eval set -- "$PARSED"

while [ "$#" -gt 0 ]; do
	case "$1" in
		--) shift; break ;;
		*)
			rc=0
			cli_getopt_eval "$1" "${2:-}" || rc=$?
			case $rc in
				1) shift ;;
				2) shift 2 ;;
				*) usage_unknown_parameter "unrecognised option: $1" "$@" ;;
			esac
			;;
	esac
done
[ "$#" -eq 1 ] || usage_unknown_parameter "takes one argument, the slideshow to write" "$@"
case "$1" in
	*.gif) ;;
	*) usage_unknown_parameter "not a .gif: $1" "$@" ;;
esac

require_command convert "ImageMagick, to draw the frames"
require_command identify "ImageMagick, to check them"

# Named from where the caller stands; read from the repository's root.
out="$(realpath -m -- "$1")"
cd "$(git rev-parse --show-toplevel)" || die "cannot enter the repository's root"

# Every PNG the listing names under docs/Screenshots, in its order.
mapfile -t shots < <(grep -oE 'docs/Screenshots/[A-Za-z0-9_.-]+\.png' appinfo/info.xml)
[ "${#shots[@]}" -gt 0 ] || die "appinfo/info.xml names no PNG under docs/Screenshots"
for shot in "${shots[@]}"; do
	require_file "$shot"
done

work="$(mktemp -d)" || die "could not create a working directory"
on_signal EXIT INT TERM -- rm -rf -- "$work"

frames=()
for i in "${!shots[@]}"; do
	frames+=( "$work/$i.png" )
	convert "${shots[$i]}" -resize '940x700>' -background "$BACKGROUND" -gravity center -extent 960x720 +repage "$work/$i.png"
	debug "frame $i: ${shots[$i]}"
done

tmp=''
replace_begin "$out" tmp
convert -delay 350 -loop 0 -dispose None "${frames[@]}" -dither None "gif:$tmp"

# Whole and opaque, every one of them, or a viewer may show the frame before.
bad=0
count=0
while read -r index geometry alpha; do
	count=$(( count + 1 ))
	case "$geometry $alpha" in
		'960x720+0+0 False' | '960x720+0+0 Undefined') ;;
		*)
			print_error "frame $index: $geometry, alpha $alpha"
			bad=1
			;;
	esac
done < <(identify -format '%s %wx%h%O %A\n' "gif:$tmp")
if [ "$count" -ne "${#shots[@]}" ]; then
	print_error "$count frames for ${#shots[@]} screenshots"
	bad=1
fi
if [ "$bad" -ne 0 ]; then
	replace_abort "$tmp"
	exit 1
fi

if replace_commit "$tmp"; then
	print_success "wrote $out: ${#shots[@]} frames"
else
	print_info "$out already held exactly this"
fi
