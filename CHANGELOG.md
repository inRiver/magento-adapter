##Version 24.8.4
- Adding PHP 8.4 support for Magento 2.4.8

##Version 24.8.3
- Adding PHP 8.3 support for Magento 2.4.7
- Fix GitHub-32464

##Version 24.8.2
- Fix import issue in plugin

##Version 24.8.1
- Support Custom Options
- Fix retry logic to the file and the image download

##Version 24.8.0
- Adaptation to the code to work on Magento 2.4.6 version

##Version 24.7.2
- Fix url rewrites after import relations
- Fix api request categories completed for Magento Open Source

##Version 24.7.1
- Fix bug with image roles on first call

##Version 24.7.0
- Added new endpoint for the 1.9.0 version of the inRiver Adapter
- Fixes issue for newly created categories where url_path and url_rewrites are not correctly generated via Magento's bulk API
- Adding retry logic to the file download for product import and product relation import
- Adding retry logic to the image download
- Added new configuration for retry amount and initial sleep for the retry logic. Image and import file has different config
- Add config to bypass forcing newly created product to disabled. You need to send the status from inriver
- Fix image roles

##Version 24.6.1
- Fix Warning: Undefined array key "managed_websites"

##Version 24.6.0
- Added new endpoint for the 1.8.0 version of the inRiver Adapter
 
##Version 24.5.0
- Adaptation to the code to work on Magento 2.4.4 version

##Version 24.4.5
- Fix the cleanup cron for "errors" folder

##Version 24.4.4
- Fix code to allow virtual product
- Fix to product import to keep website associated to a product that is not managed by the adapter
- Fix the email in the source code
- Fix code to allow virtual product
- Fix product import to allow websites not managed by the adapter
- Added Archiving of imported file for product import
- Added a new config: "Archive Lifetime in days" (default: 30)
- Added patch PATCH_INRIVER_MAGENTO_ADAPTER_2.4.3-p2-Bulk-Api.patch to replace MDVA-40896_EE_2.4.3_COMPOSER_v1.patch for magento >=2.4.3-p2 as it was not fixing the issue anymore. If you are on 2.4.3-p2, please apply this new patch

##Version 24.4.3
- Fix an error with the implementation of endpoint products/import/relations
- Fix the list of special characters according to the list in the inRiver adapter
- Fix a type cast error when isDebug is set in the config

##Version 24.4.2
- Fix an error with bulk processing when the bulk api call doesn't comes from inriver

##Version 24.4.1
- Include patch MDVA-40896 to fix error between bulk operation and Magento_ReCaptchaWebapiRest. If you are on 2.4.3 and have this extension active you have to apply the patch under \\wsl$\Ubuntu\home\absolunet\project\Adapters-Magento-Module\Inriver\Adapter\src\Patch
- Split category assignement into add and remove call to allow the option to skip category handling from inRiver in certain store
 
##Version 24.4.0
- Update images error messages to include file name and product sku
- Adding new move category endpoint to have more control on error messages
- Change Operation Id in response message for Operation Key to match the key in the request message

##Version 24.3.3
- Forced the cleaning of the Product Repository cache in the Product Category Assignment operation to make sure it's loaded properly for editing
- Added start and finished logs to Attribute Options, product category assignment and product images Operations

##Version 24.3.2
- Fixed some exception handling and added information

##Version 24.3.1
- Added support for text swatch
- Added support for hexadecimal color in visual swatches

##Version 24.3.0
- As an additional security layer, the configured inRiver API Key is now stored encrypted in the database in addition to being obscured in the admin panel.
- WARNING: This introduces a breaking change in the configuration. You must re-enter the API Key in order to encrypt it, as the system now assumes an encrypted key.
- Add functionality to skip errors and continue import when there is validation error in the import.
- Added a new setting to specify the amount of validation error allowed before stopping all the import. Set to 999999 by default. 

##Version 24.2.0
- Better error message when an attribute option value is invalid. 
- Continue processing other option value if one is invalid

##Version 24.1.1
- Fix Error while serializing result data to generate callback
- Fix inRiver Callback to handle exception in the additional message validation and send it back to inRiver
- Fix code Magento Coding Standards
- Fix Sql Error in the handling of configurable relations

##Version 24.1.0
- Added clear logs on the inRiver callback when the api key is empty or invalid

##Version 1.2.2
- Added new lighter api endpoint route to get all skus and product types
- Fix code Magento Coding Standards 

##Version 1.2.1

Updated magento version to 2.4.1

- Added support for bundle products
- Remove custom update function for product attribute option API call as Magento has now is own function

##Version 1.2.0

Updated magento version to 2.4

- Fix issues with async calls because of non backward compatible changes
- Change the OperationRepositoryPlugin to be a BulkManagementPlugin as we need to be hooked differently to save the callbackURL
- Changed AddCallback plugin since the database changes required us to adapt with new code

Various Bug fixes
- Fix bug with configurable products that were losing relations with simple after importing cross-sell, upsell, related
- Fix bug with grouped that were losing its children when importing relations
- Updated unit tests 

##Version 0.6.0
- Added relations import
- Release on 2.3.5
