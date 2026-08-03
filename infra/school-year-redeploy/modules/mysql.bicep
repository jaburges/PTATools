param location string
param serverName string
param skuName string
param storageGb int
param administratorLogin string

@secure()
param administratorPassword string

param databaseName string
param delegatedSubnetId string
param privateDnsZoneId string
param entraAdminObjectId string
param entraAdminDisplayName string
param tags object

resource mysql 'Microsoft.DBforMySQL/flexibleServers@2023-12-30' = {
  name: serverName
  location: location
  tags: tags
  sku: {
    name: skuName
    tier: 'Burstable'
  }
  properties: {
    version: '8.0.21'
    administratorLogin: administratorLogin
    administratorLoginPassword: administratorPassword
    storage: {
      storageSizeGB: storageGb
      autoGrow: 'Enabled'
    }
    backup: {
      backupRetentionDays: 7
      geoRedundantBackup: 'Disabled'
    }
    highAvailability: {
      mode: 'Disabled'
    }
    network: {
      delegatedSubnetResourceId: delegatedSubnetId
      privateDnsZoneResourceId: privateDnsZoneId
      publicNetworkAccess: 'Disabled'
    }
  }
}

resource database 'Microsoft.DBforMySQL/flexibleServers/databases@2023-12-30' = {
  parent: mysql
  name: databaseName
  properties: {
    charset: 'utf8mb4'
    collation: 'utf8mb4_0900_ai_ci'
  }
}

resource entraAdmin 'Microsoft.DBforMySQL/flexibleServers/administrators@2023-12-30' = {
  parent: mysql
  name: 'ActiveDirectory'
  properties: {
    administratorType: 'ActiveDirectory'
    login: entraAdminDisplayName
    sid: entraAdminObjectId
    tenantId: subscription().tenantId
  }
}

// Common WordPress-friendly server parameters
resource charsetParam 'Microsoft.DBforMySQL/flexibleServers/configurations@2023-12-30' = {
  parent: mysql
  name: 'character_set_server'
  properties: {
    value: 'utf8mb4'
    source: 'user-override'
  }
}

resource collationParam 'Microsoft.DBforMySQL/flexibleServers/configurations@2023-12-30' = {
  parent: mysql
  name: 'collation_server'
  properties: {
    value: 'utf8mb4_0900_ai_ci'
    source: 'user-override'
  }
}

output id string = mysql.id
output name string = mysql.name
output fqdn string = mysql.properties.fullyQualifiedDomainName
