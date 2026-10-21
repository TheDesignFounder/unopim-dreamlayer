# UnoPIM dependency prerequisite

The supported October 19 source revision has four advisories affecting two
packages in its original lockfile: `enshrined/svg-sanitize` 0.22.0 and
`symfony/http-foundation` 8.1.6. These are existing UnoPIM dependencies.

For the 0.2.16 validation installation, update only these packages before
installing the extension:

```sh
composer require enshrined/svg-sanitize:1.1.1 symfony/http-foundation:8.1.9 --minimal-changes
composer audit
```

These are the exact versions used in this release's compatibility workflow.
Run the commands inside the host's PHP container when using Docker. Keep the
resulting host `composer.json` and `composer.lock` together. This changes the
host's dependency constraints, not its chat-hook source. Do not disable Composer
security checks or update unrelated dependencies to get past installation.

The compatibility baseline is the documented UnoPIM source **plus these two
dependency updates**. Later versions require a new compatibility run.
