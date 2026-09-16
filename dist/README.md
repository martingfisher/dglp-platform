# Built plugin zip

`dgl-platform.zip` is the deployable build of whatever version `main` is at.

It is committed rather than attached to a GitHub release because releases
cannot be created from the environment this is built in, and because the
alternative route does not work: a GitHub **archive** URL
(`/archive/refs/heads/main.zip`) unpacks to a top-level folder named
`dglp-platform-main`, and WordPress names a plugin after its top-level folder.
Installing that would create a second, duplicate plugin directory rather than
upgrading `dgl-platform`.

This file has the right folder name, so it installs over the top:

```
wp plugin install https://raw.githubusercontent.com/martingfisher/dglp-platform/main/dist/dgl-platform.zip --force --activate
```

`--force` matters. Without it `wp plugin install` sees the plugin is already
present, reports success and changes nothing.

Rebuild it with the recipe in `docs/deployment.md` whenever the version changes.
