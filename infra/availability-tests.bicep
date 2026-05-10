// Phase C.1: App Insights availability test for wilderptsa.net
// Pings prod URL every 5 min from 5 US regions, asserts HTTP 200 + content match "Wilder PTSA".
// Cost: $0 (free up to 1M tests/mo; this uses ~43k/mo).

param location string = resourceGroup().location
param appInsightsName string = 'wilderptsa-appinsights'
param siteUrl string = 'https://wilderptsa.net/'
param testName string = 'wilderptsa-prod-availability'

resource appInsights 'Microsoft.Insights/components@2020-02-02' existing = {
  name: appInsightsName
}

resource availabilityTest 'Microsoft.Insights/webtests@2022-06-15' = {
  name: testName
  location: location
  tags: {
    'hidden-link:${appInsights.id}': 'Resource'
    purpose: 'continuous-availability'
    managedBy: 'PTATools/infra/availability-tests.bicep'
  }
  kind: 'standard'
  properties: {
    SyntheticMonitorId: testName
    Name: 'wilderptsa.net availability'
    Description: 'Pings prod URL every 5 min from 5 US regions; asserts HTTP 200 and content "Wilder PTSA"'
    Enabled: true
    Frequency: 300
    Timeout: 30
    Kind: 'standard'
    RetryEnabled: true
    Locations: [
      { Id: 'us-ca-sjc-azr' }   // Southern California
      { Id: 'us-tx-sn1-azr' }   // South Central US (Texas)
      { Id: 'us-il-ch1-azr' }   // North Central US (Illinois)
      { Id: 'us-va-ash-azr' }   // East US (Virginia)
      { Id: 'us-fl-mia-edge' }  // Southeast (Florida)
    ]
    Request: {
      RequestUrl: siteUrl
      HttpVerb: 'GET'
      ParseDependentRequests: false
      FollowRedirects: true
    }
    ValidationRules: {
      ExpectedHttpStatusCode: 200
      SSLCheck: true
      SSLCertRemainingLifetimeCheck: 7
      ContentValidation: {
        ContentMatch: 'Wilder PTSA'
        IgnoreCase: true
        PassIfTextFound: true
      }
    }
  }
}

output testId string = availabilityTest.id
output testName string = availabilityTest.name
