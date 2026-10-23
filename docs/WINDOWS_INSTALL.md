# Windows installations

Use the PHP environment supported by your existing UnoPim installation, such as WSL 2 or its Linux containers. Follow the distribution/Composer commands in [README.md](../README.md) inside that environment.

The extension does not require cloning UnoPim or changing its dependencies. Mount the extension package and private image storage into both the web and worker containers. Run the worker on the configured asynchronous connection and ensure the supervisor restarts it after exit.

The compatibility checks for this candidate ran in Linux containers on macOS. Native Windows PHP execution has not been certified. Filesystems used for private previews must support atomic rename.
