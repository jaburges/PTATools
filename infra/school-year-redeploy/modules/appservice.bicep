param location string
param planName string
param webAppName string
param skuName string
param appSubnetId string
param identityId string
param identityClientId string
param databaseHost string
param databaseName string
param databaseUsername string
param appInsightsConnectionString string
param storageAccountName string
param deployStagingSlot bool
param tags object

var skuTier = contains(skuName, 'P') ? 'PremiumV3' : 'Basic'

resource plan 'Microsoft.Web/serverfarms@2023-12-01' = {
  name: planName
  location: location
  tags: tags
  kind: 'linux'
  sku: {
    name: skuName
    tier: skuTier
  }
  properties: {
    reserved: true
  }
}

resource web 'Microsoft.Web/sites@2023-12-01' = {
  name: webAppName
  location: location
  tags: tags
  kind: 'app,linux'
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: {
      '${identityId}': {}
    }
  }
  properties: {
    serverFarmId: plan.id
    httpsOnly: true
    virtualNetworkSubnetId: appSubnetId
    siteConfig: {
      linuxFxVersion: 'PHP|8.3'
      alwaysOn: startsWith(skuName, 'P')
      ftpsState: 'Disabled'
      minTlsVersion: '1.2'
    }
  }
}

resource staging 'Microsoft.Web/sites/slots@2023-12-01' = if (deployStagingSlot) {
  parent: web
  name: 'staging'
  location: location
  tags: tags
  kind: 'app,linux'
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: {
      '${identityId}': {}
    }
  }
  properties: {
    serverFarmId: plan.id
    httpsOnly: true
    virtualNetworkSubnetId: appSubnetId
    siteConfig: {
      linuxFxVersion: 'PHP|8.3'
      ftpsState: 'Disabled'
      minTlsVersion: '1.2'
    }
  }
}

// Keep client id available for app code that needs it
resource webSettings 'Microsoft.Web/sites/config@2023-12-01' = {
  parent: web
  name: 'appsettings'
  properties: {
    DATABASE_HOST: databaseHost
    DATABASE_NAME: databaseName
    DATABASE_USERNAME: databaseUsername
    ENABLE_MYSQL_MANAGED_IDENTITY: 'true'
    ENABLE_BLOB_MANAGED_IDENTITY: 'true'
    STORAGE_ACCOUNT_NAME: storageAccountName
    BLOB_STORAGE_ENABLED: 'true'
    APPLICATIONINSIGHTS_CONNECTION_STRING: appInsightsConnectionString
    ApplicationInsightsAgent_EXTENSION_VERSION: '~3'
    WEBSITES_ENABLE_APP_SERVICE_STORAGE: 'true'
    AFD_ENABLED: 'false'
    MANAGED_IDENTITY_CLIENT_ID: identityClientId
  }
}

output id string = web.id
output name string = web.name
output defaultHostName string = web.properties.defaultHostName
output planName string = plan.name
output stagingHostName string = deployStagingSlot ? staging!.properties.defaultHostName : ''
