using './main.bicep'

param location = 'westus2'
param namePrefix = 'wilderptsa-sy'
param appServiceSkuName = 'B1'
param mysqlSkuName = 'Standard_B1ms'
param mysqlStorageGb = 64
param mysqlAdminLogin = 'ptsadbadmin'
// Provide at deploy time:
//   az deployment group create ... -p mysqlAdminPassword='<secret>'
param mysqlAdminPassword = ''
param wordpressDatabaseName = 'wilderptsa_database'
param deployRedis = true
param deployFrontDoor = true
param deployStagingSlot = false
param deploySummerSwa = false
param vnetAddressPrefix = '10.1.0.0/23'
param appSubnetPrefix = '10.1.0.0/25'
param dbSubnetPrefix = '10.1.1.0/25'
param aciSubnetPrefix = '10.1.0.128/28'
param tags = {
  project: 'WilderPTSA'
  purpose: 'school-year-redeploy'
  managedBy: 'bicep'
}
