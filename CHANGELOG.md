# Changelog

## Version 24.8.5
- Added PHP 8.5 support for Magento 2.4.9.
- Fixed image-role cleanup in single-store Magento: preserved the shared store scope and removed obsolete roles in the same scope where Magento saves them.
- Fixed access to the inRiver configuration for custom admin roles by defining the missing ACL resource.
- Expanded automated test coverage with additional unit tests and API integration tests for the main adapter workflows.

## Version 24.8.4
- Added PHP 8.4 support for Magento 2.4.8.

## Version 24.8.3
- Added PHP 8.3 support for Magento 2.4.7.
- Fixed GitHub-32464.

## Version 24.8.2
- Fixed an import issue in the plugin.

## Version 24.8.1
- Added support for custom options.
- Fixed retry logic for file and image downloads.

## Version 24.8.0
- Adapted the code to work with Magento 2.4.6.

## Version 24.7.3
- Added support for custom options.
- Fixed retry logic for file and image downloads.

## Version 24.7.2
- Fixed URL rewrites after importing relations.
- Fixed the completed categories API request for Magento Open Source.

## Version 24.7.1
- Fixed a bug with image roles on the first call.

## Version 24.7.0
- Added a new endpoint for version 1.9.0 of the inRiver Adapter.
- Added retry logic to file downloads for product imports and product relation imports.
- Added retry logic to image downloads.
- Added new configuration options for the retry count and initial delay. Image and import files have separate configurations.
- Added a configuration option to avoid forcing newly created products to be disabled. The status must be sent from inRiver.
- Fixed an issue with newly created categories where `url_path` and `url_rewrites` were not generated correctly through Magento's bulk API.
- Fixed image roles.

## Version 24.6.1
- Fixed the `Warning: Undefined array key "managed_websites"` warning.

## Version 24.6.0
- Added a new endpoint for version 1.8.0 of the inRiver Adapter.

## Version 24.5.0
- Adapted the code to work with Magento 2.4.4.

## Version 24.4.5
- Fixed the cleanup cron job for the `errors` folder.

## Version 24.4.4
- Added archiving of imported files for product imports.
- Added a new `Archive Lifetime in days` configuration option with a default value of 30.
- Added the `PATCH_INRIVER_MAGENTO_ADAPTER_2.4.3-p2-Bulk-Api.patch` patch to replace `MDVA-40896_EE_2.4.3_COMPOSER_v1.patch` for Magento 2.4.3-p2 and later because the previous patch no longer fixed the issue. If you are using Magento 2.4.3-p2, apply this new patch.
- Fixed the code to allow virtual products.
- Fixed product imports to preserve websites associated with a product when they are not managed by the adapter.
- Fixed the email address in the source code.

## Version 24.4.3
- Fixed an error in the implementation of the `products/import/relations` endpoint.
- Fixed the list of special characters according to the list in the inRiver Adapter.
- Fixed a type-casting error when `isDebug` is set in the configuration.

## Version 24.4.2
- Fixed a bulk-processing error when the bulk API call does not come from inRiver.

## Version 24.4.1
- Included the `MDVA-40896` patch to fix an error between bulk operations and `Magento_ReCaptchaWebapiRest`. If you are using Magento 2.4.3 and have this extension enabled, apply the patch located in `Inriver/Adapter/src/Patch`.
- Split category assignment into separate add and remove calls to allow category handling from inRiver to be skipped in certain stores.

## Version 24.4.0
- Added a new move-category endpoint to provide more control over error messages.
- Updated image error messages to include the file name and product SKU.
- Changed `Operation Id` to `Operation Key` in response messages to match the key in request messages.

## Version 24.3.3
- Added start and finish logs for attribute options, product category assignments, and product image operations.
- Forced the Product Repository cache to be cleared during the Product Category Assignment operation to ensure that it is loaded correctly for editing.

## Version 24.3.2
- Fixed exception handling and added more information.

## Version 24.3.1
- Added support for text swatches.
- Added support for hexadecimal colors in visual swatches.

## Version 24.3.0
- Added functionality to skip validation errors and continue an import.
- Added a new setting to specify the number of validation errors allowed before stopping the entire import. The default value is 999999.
- The configured inRiver API key is now stored encrypted in the database, in addition to being obscured in the admin panel, as an additional security layer.
- **Breaking change:** You must re-enter the API key so that it can be encrypted because the system now assumes that the key is encrypted.

## Version 24.2.0
- Improved the error message shown when an attribute option value is invalid.
- Continued processing other option values when one is invalid.

## Version 24.1.1
- Fixed an error that occurred while serializing result data to generate a callback.
- Fixed the inRiver callback to handle exceptions during additional message validation and send them back to inRiver.
- Fixed Magento Coding Standards issues.
- Fixed an SQL error when handling configurable relations.

## Version 24.1.0
- Added clear logs to the inRiver callback when the API key is empty or invalid.

## Version 1.2.2
- Added a new, lighter API endpoint route to retrieve all SKUs and product types.
- Fixed Magento Coding Standards issues.

## Version 1.2.1
- Added support for bundle products.
- Updated Magento to version 2.4.1.
- Removed the custom update function for product attribute option API calls because Magento now provides its own function.

## Version 1.2.0
- Updated Magento to version 2.4.
- Changed `OperationRepositoryPlugin` to `BulkManagementPlugin` because a different hook is required to save the `callbackURL`.
- Changed the `AddCallback` plugin to adapt to the required database changes.
- Updated unit tests.
- Fixed issues with asynchronous calls caused by non-backward-compatible changes.
- Fixed a bug where configurable products lost their relations with simple products after importing cross-sell, upsell, and related-product relations.
- Fixed a bug where grouped products lost their child products when importing relations.

## Version 0.6.0
- Added relation imports.
- Added support for Magento 2.3.5.
