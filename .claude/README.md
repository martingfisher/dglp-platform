# Claude Code settings for this repo

## Status line

`settings.json` points Claude Code at `statusline.sh`, which prints one line at
the bottom of the session:

```
Opus 5  ·  ctx 78% (781k/1.0M)  ·  main*
```

- **Model** currently answering.
- **Context** used as a percentage of the window, with the raw figures after it.
  Green under 60%, amber from 60%, red from 85%. The window is detected: 1M when
  the model id carries the `[1m]` suffix or the session has already passed 200k,
  otherwise 200k.
- **Branch** a commit would land on. A trailing `*` means the working tree is
  dirty. A detached checkout shows `detached@<sha>` rather than implying a
  branch.

Context is read from the session transcript rather than the payload, because the
payload does not carry a token count in every Claude Code version. Only the last
400 lines are parsed: the transcript reaches tens of megabytes in a long session
and this redraws constantly.

### Changing it

Edit `statusline.sh`. It takes the session JSON on stdin and prints one line; it
is plain bash plus `jq`. To drop a section, delete its `printf` argument at the
bottom. To change the colour thresholds, edit the `pct` comparison.

To turn it off, delete `statusLine` from `settings.json`.

To use it in every repository rather than just this one, copy the script
somewhere central and put the same `statusLine` block in `~/.claude/settings.json`
with an absolute path.

Requires `jq`. Without it the line says so instead of failing silently.
