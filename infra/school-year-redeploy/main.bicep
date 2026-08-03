@description('Azure region')
param location string = resourceGroup().location

@description('Name prefix for new resources (avoid colliding with live wilderptsa-* until cutover)')
param namePrefix string = 'wilderptsa-sy'

@description('App Service Plan SKU name (B1 for lean reopen; P1v3 when staging slots required)')
@allowed(['B1', 'B2', 'P0v3', 'P1v3'])
param appServiceSkuName string = 'B1'

@description('MySQL Flexible Server SKU')
param mysqlSkuName string = 'Standard_B1ms'

@description('MySQL storage GB (cannot shrink later)')
@minValue(20)
@maxValue(256)
param mysqlStorageGb int = 64

@description('MySQL administrator login')
param mysqlAdminLogin string = 'ptsadbadmin'

@secure()
@description('MySQL administrator password (bootstrap/import only; prefer managed identity for WordPress)')
param mysqlAdminPassword string

@description('WordPress database name')
param wordpressDatabaseName string = 'wilderptsa_database'

@description('Deploy Redis Basic C0')
param deployRedis bool = true

@description('Deploy Front Door profile + endpoint for the new web app')
param deployFrontDoor bool = true

@description('Deploy staging slot (requires Premium plan for best support)')
param deployStagingSlot bool = false

@description('Create Free Static Web App for summer placeholder (set false if already exists)')
param deploySummerSwa bool = false

@description('VNet address space')
param vnetAddressPrefix string = '10.1.0.0/23'

@description('App subnet prefix (must be within VNet)')
param appSubnetPrefix string = '10.1.0.0/25'

@description('MySQL subnet prefix')
param dbSubnetPrefix string = '10.1.1.0/25'

@description('ACI subnet prefix')
param aciSubnetPrefix string = '10.1.0.128/28'

@description('Tags applied to all resources')
param tags object = {
  project: 'WilderPTSA'
  purpose: 'school-year-redeploy'
  managedBy: 'bicep'
}

var planName = '${namePrefix}-asp'
var webAppName = '${namePrefix}-web'
var mysqlName = '${namePrefix}-mysql'
var redisName = '${namePrefix}-redis'
var storageName = take(replace(toLower('${namePrefix}stg${uniqueString(resourceGroup().id)}'), '-', ''), 24)
var vnetName = '${namePrefix}-vnet'
var identityName = '${namePrefix}-wpidentity'
var lawName = '${namePrefix}-laws'
var aiName = '${namePrefix}-appinsights'
var afdProfileName = '${namePrefix}-afd'
var afdEndpointName = '${namePrefix}-afd-ep'
var privateDnsZoneName = 'privatelink.mysql.database.azure.com'
var swaName = '${namePrefix}-summer-swa'

module networking 'modules/networking.bicep' = {
  name: 'networking'
  params: {
    location: location
    vnetName: vnetName
    vnetAddressPrefix: vnetAddressPrefix
    appSubnetPrefix: appSubnetPrefix
    dbSubnetPrefix: dbSubnetPrefix
    aciSubnetPrefix: aciSubnetPrefix
    privateDnsZoneName: privateDnsZoneName
    tags: tags
  }
}

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: identityName
  location: location
  tags: tags
}

module mysql 'modules/mysql.bicep' = {
  name: 'mysql'
  params: {
    location: location
    serverName: mysqlName
    skuName: mysqlSkuName
    storageGb: mysqlStorageGb
    administratorLogin: mysqlAdminLogin
    administratorPassword: mysqlAdminPassword
    databaseName: wordpressDatabaseName
    delegatedSubnetId: networking.outputs.dbSubnetId
    privateDnsZoneId: networking.outputs.privateDnsZoneId
    entraAdminObjectId: identity.properties.principalId
    entraAdminDisplayName: identityName
    tags: tags
  }
}

module monitoring 'modules/monitoring.bicep' = {
  name: 'monitoring'
  params: {
    location: location
    logAnalyticsName: lawName
    appInsightsName: aiName
    tags: tags
  }
}

module storage 'modules/storage.bicep' = {
  name: 'storage'
  params: {
    location: location
    storageAccountName: storageName
    identityPrincipalId: identity.properties.principalId
    tags: tags
  }
}

module appservice 'modules/appservice.bicep' = {
  name: 'appservice'
  params: {
    location: location
    planName: planName
    webAppName: webAppName
    skuName: appServiceSkuName
    appSubnetId: networking.outputs.appSubnetId
    identityId: identity.id
    identityClientId: identity.properties.clientId
    databaseHost: mysql.outputs.fqdn
    databaseName: wordpressDatabaseName
    databaseUsername: identityName
    appInsightsConnectionString: monitoring.outputs.connectionString
    storageAccountName: storage.outputs.name
    deployStagingSlot: deployStagingSlot
    tags: tags
  }
}

module redis 'modules/redis.bicep' = if (deployRedis) {
  name: 'redis'
  params: {
    location: location
    redisName: redisName
    tags: tags
  }
}

module frontdoor 'modules/frontdoor.bicep' = if (deployFrontDoor) {
  name: 'frontdoor'
  params: {
    profileName: afdProfileName
    endpointName: afdEndpointName
    originGroupName: '${namePrefix}-origin-group'
    originName: 'origin-app'
    originHostName: appservice.outputs.defaultHostName
    tags: tags
  }
}

module summerSwa 'modules/summer-swa.bicep' = if (deploySummerSwa) {
  name: 'summer-swa'
  params: {
    location: location
    name: swaName
    tags: tags
  }
}

output webAppName string = appservice.outputs.name
output webAppDefaultHostName string = appservice.outputs.defaultHostName
output mysqlFqdn string = mysql.outputs.fqdn
output mysqlDatabaseName string = wordpressDatabaseName
output identityName string = identity.name
output identityClientId string = identity.properties.clientId
output identityPrincipalId string = identity.properties.principalId
output storageAccountName string = storage.outputs.name
output redisHostName string = deployRedis ? redis!.outputs.hostName : ''
output frontDoorEndpointHostName string = deployFrontDoor ? frontdoor!.outputs.endpointHostName : ''
output appInsightsConnectionString string = monitoring.outputs.connectionString
output summerSwaHostName string = deploySummerSwa ? summerSwa!.outputs.defaultHostname : ''
output nextSteps string = 'Restore DB dump → deploy wwwroot from jaburges/wilderwebsite → set Redis app settings if enabled → point AFD custom domain / origin from SWA back to this web app.'
