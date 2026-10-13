# UnoPIM dependency prerequisite

The tested fresh UnoPIM source used `enshrined/svg-sanitize` 1.0.0. Some UnoPIM
3.x revisions still constrain the existing host dependency to 0.22.x, which
Composer may block because of advisories. DreamLayer does not introduce this
dependency; see [upstream PR #748](https://github.com/unopim/unopim/pull/748).

Use a host revision with that prerequisite resolved. If preparing an isolated
review installation on the older baseline, update this host dependency separately
before requiring DreamLayer. From the UnoPIM application directory:

```sh
composer require 'enshrined/svg-sanitize:^1.0' --minimal-changes
```

For the upstream development Docker stack, run it inside PHP instead:

```sh
docker compose -f compose.dev.yaml exec -T unopim-fpm composer require 'enshrined/svg-sanitize:^1.0' --minimal-changes
```

Keep that host dependency change separate from the generic AiAgent hooks PR.
The local preparation validated the sanitizer change against UnoPIM's upload
and preview tests (9 tests / 26 assertions). Do not turn off Composer security
checks or apply an unrelated full dependency update to get past installation.
