# Compatibility and upgrades

DreamLayer's image editor uses UnoPim product APIs and its own admin page. It does
not require core patches or a development branch. Native chat integration is an
optional addition, available only when the installed host provides the required
extension registries.

See COMPATIBILITY.md for the tested release matrix. If an existing installation
has old DreamLayer core patches, reconcile it with an official UnoPim release
before installing this extension. Back up the installation and database first.
This package does not automatically remove or modify host code.
