#!/usr/bin/env bash
#
# Claude Code status line for the DGLP Platform repo.
#
# Reads the session payload on stdin and prints one line:
#
#   Opus 5  ·  ctx 77% (775k/1M)  ·  main*
#
# Model, how full the context window is, and the branch any commit would land
# on. The asterisk means the working tree is dirty.
#
# Every lookup degrades to a dash rather than an error: a status line that
# prints a stack trace is worse than one that prints nothing.

set -uo pipefail

payload="$(cat)"

# --- helpers ---------------------------------------------------------------

have() { command -v "$1" >/dev/null 2>&1; }

field() {
	if have jq; then
		printf '%s' "$payload" | jq -r "$1 // empty" 2>/dev/null
	fi
}

# Thousands separators are noise at a glance; 775k reads faster than 775,231.
human() {
	local n="${1:-0}"
	if [ "$n" -ge 1000000 ] 2>/dev/null; then
		awk -v n="$n" 'BEGIN { printf "%.1fM", n / 1000000 }'
	elif [ "$n" -ge 1000 ] 2>/dev/null; then
		awk -v n="$n" 'BEGIN { printf "%dk", n / 1000 }'
	else
		printf '%s' "$n"
	fi
}

DIM=$'\033[2m'
RESET=$'\033[0m'
BLUE=$'\033[34m'
GREEN=$'\033[32m'
AMBER=$'\033[33m'
RED=$'\033[31m'
SEP="${DIM}  ·  ${RESET}"

if ! have jq; then
	# Nothing useful can be parsed, so say why rather than printing a blank bar.
	printf '%s' "${DIM}status line needs jq${RESET}"
	exit 0
fi

# --- model -----------------------------------------------------------------

model="$(field '.model.display_name')"
model_id="$(field '.model.id')"
[ -n "$model" ] || model="${model_id:-unknown model}"

# --- context window --------------------------------------------------------

transcript="$(field '.transcript_path')"
exceeds="$(field '.exceeds_200k_tokens')"
used=0

if [ -n "$transcript" ] && [ -r "$transcript" ]; then
	# The last usage record holds the running total: fresh input, plus what was
	# written to cache, plus what was read back from it. Only the tail is read,
	# because this file reaches tens of megabytes in a long session and the
	# status line redraws constantly.
	used="$(tail -n 400 "$transcript" 2>/dev/null \
		| jq -r 'select(.message.usage != null)
			| (.message.usage.input_tokens // 0)
			+ (.message.usage.cache_creation_input_tokens // 0)
			+ (.message.usage.cache_read_input_tokens // 0)' 2>/dev/null \
		| tail -1)"
fi

case "$used" in
	''|*[!0-9]*) used=0 ;;
esac

# Default to the standard window, then take any evidence of a larger one.
window=200000
case "$model_id" in
	*1m*|*1M*) window=1000000 ;;
esac
[ "$exceeds" = "true" ] && window=1000000
[ "$used" -gt 200000 ] && window=1000000

pct=0
[ "$window" -gt 0 ] && pct=$(( used * 100 / window ))

if [ "$pct" -ge 85 ]; then
	ctx_colour="$RED"
elif [ "$pct" -ge 60 ]; then
	ctx_colour="$AMBER"
else
	ctx_colour="$GREEN"
fi

context="${ctx_colour}ctx ${pct}%${RESET} ${DIM}($(human "$used")/$(human "$window"))${RESET}"

# --- branch ----------------------------------------------------------------

dir="$(field '.workspace.current_dir')"
[ -n "$dir" ] || dir="$(field '.cwd')"
[ -n "$dir" ] || dir="$PWD"

branch_part="${DIM}no repo${RESET}"

if have git && git -C "$dir" rev-parse --git-dir >/dev/null 2>&1; then
	branch="$(git -C "$dir" rev-parse --abbrev-ref HEAD 2>/dev/null)"

	if [ "$branch" = "HEAD" ]; then
		# Detached: a commit here lands nowhere anybody will find it, so name
		# the sha rather than implying a branch.
		branch="detached@$(git -C "$dir" rev-parse --short HEAD 2>/dev/null)"
	fi

	dirty=""
	if [ -n "$(git -C "$dir" status --porcelain 2>/dev/null | head -1)" ]; then
		dirty="*"
	fi

	branch_part="${BLUE}${branch}${dirty}${RESET}"
fi

printf '%s%s%s%s%s' "$model" "$SEP" "$context" "$SEP" "$branch_part"
