# Host requirements

Use an unmodified released UnoPim installation and its locked dependencies. The image editor does not require the AI Agent package or `laravel/ai`.

Composer requires `unopim/unopim` because that is the actual application package name in the released hosts. There is no published `unopim/core` dependency in those root manifests. UnoPim autoloads its local Webkul core package; adding an invented core dependency would make installation fail. The explicit host constraint is in the extension's `composer.json`; the tested tags are listed in [COMPATIBILITY.md](COMPATIBILITY.md).

Do not edit host dependency versions or apply patches. Installing the extension should add only `dreamlayer/unopim-dreamlayer`, retaining every existing locked host dependency. Normal Composer registration and package discovery are required. Database migrations add extension-owned tables/columns only.

Native chat is an optional adapter. It requires the host's composer-reference and result-renderer registries and compatible AI tool interfaces. The extension checks for those capabilities before loading native classes. Missing chat hooks disable only the adapter. Missing fundamental product APIs disable the extension with a log warning instead of throwing during startup.
