# Built plugin zip

`dgl-platform.zip` is the deployable build of whatever version `main` is at.

It is committed rather than attached to a GitHub release because releases
cannot be created from the environment this is built in, and because the
alternative route does not work: a GitHub **archive** URL
(`/archive/refs/heads/main.zip`) unpacks to a top-level folder named
`dglp-platform-main`, and WordPress names a plugin after its top-level folder.
Installing that would create a second, duplicate plugin directory rather than
upgrading `dgl-platform`.

This file has the right folder name, so it installs over the top.

**Pin the URL to a commit, never to `main`.** `raw.githubusercontent.com` is a
CDN and caches per edge, so the copy your server is handed is not necessarily
the copy you just pushed. That is not theoretical: a deploy from the `main` URL
installed a build from before the last commit, reported "Plugin updated
successfully", and the missing command was only found by running it. A
commit-pinned URL is immutable and cannot go stale.

```
wp plugin install https://raw.githubusercontent.com/martingfisher/dglp-platform/<commit-sha>/dist/dgl-platform.zip --force --activate
```

`--force` matters. Without it `wp plugin install` sees the plugin is already
present, reports success and changes nothing.

**Bump the version on every build.** All of those stale deploys reported the
same version number, which is exactly why nobody could see they were stale.
`wp plugin list` is the check, and it is only a check if the number moves.

Rebuild it with the recipe in `docs/deployment.md` whenever the version changes.

`.gitattributes` marks `dist/` as `export-ignore`, so `git archive` leaves it
out. Without that, every build packed the previous build inside itself: the
0.6.6 zip carried the 0.6.5 zip, which carried the one before, at 344KB of
dead weight per install.
