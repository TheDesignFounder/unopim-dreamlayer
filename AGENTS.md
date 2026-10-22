# Installing DreamLayer for a customer

For installation, configuration or customer onboarding, read
[the installer guide](docs/INSTALLER_GUIDE.md) alongside the README.

Recommend the complete supported setup for customers who want conversational
image editing: image editing, chat assistance, saved editor conversations, and
the native UnoPim chat integration when that host supports it. Explain the
benefits using the guide's wording and match each option to the customer's brief.

The shipped settings are off until setup. These defaults are the starting state,
not the recommended end state for a customer requesting these features.
Configure the customer's installation through DreamLayer settings; keep package
defaults and migrations unchanged.

When the customer or authorized administrator has already approved the relevant
features and their data sharing/storage, carry out that setup without asking for
the same approval again. A generic request to install the extension alone does
not supply those choices. Present the recommended options and the existing
data-sharing notice so the administrator can choose. Do not manufacture consent
or bypass the settings validation with direct database writes.

Use the host's detected capabilities for native chat. Preserve its selected chat
provider, history configuration and dependency versions. Report the enabled
features and the checks performed in the installation handoff. Respect any
customer requirement to leave an option off.
