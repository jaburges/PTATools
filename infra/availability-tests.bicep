// App Insights availability test for wilderptsa.net, plus an email alert when it fails.
// One location every 15 minutes is ~2,900 runs/month (~$1.50); five locations every
// 5 minutes would be ~$22/month.
//
// Deploy:
//   az deployment group create -g PTSAWebsite -f infra/availability-tests.bicep \
//     -p alertEmail=<address>

param location string = resourceGroup().location
param appInsightsName string = 'wilderptsa-appinsights'
param siteUrl string = 'https://wilderptsa.net/'
param testName string = 'wilderptsa-prod-availability'

@description('Who gets the "site is down" email. Passed at deploy time; the repo is public.')
param alertEmail string

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
    Description: 'Checks the homepage every 15 min from one US region; asserts HTTP 200 and content "Wilder PTSA"'
    Enabled: true
    Frequency: 900
    Timeout: 30
    Kind: 'standard'
    RetryEnabled: true
    Locations: [
      { Id: 'us-ca-sjc-azr' } // Southern California
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

resource siteDownGroup 'Microsoft.Insights/actionGroups@2023-01-01' = {
  name: 'wilderptsa-site-down'
  location: 'global'
  properties: {
    groupShortName: 'site-down'
    enabled: true
    emailReceivers: [
      {
        name: 'site-owner'
        emailAddress: alertEmail
        useCommonAlertSchema: true
      }
    ]
  }
}

// Each run already retries before it records a failure, and a 30-minute window
// holds two runs, so a single slow response doesn't send an email.
resource siteDownAlert 'Microsoft.Insights/metricAlerts@2018-03-01' = {
  name: 'wilderptsa-site-down'
  location: 'global'
  properties: {
    description: 'wilderptsa.net homepage check failed'
    severity: 1
    enabled: true
    scopes: [appInsights.id]
    evaluationFrequency: 'PT15M'
    windowSize: 'PT30M'
    autoMitigate: true
    criteria: {
      'odata.type': 'Microsoft.Azure.Monitor.SingleResourceMultipleMetricCriteria'
      allOf: [
        {
          criterionType: 'StaticThresholdCriterion'
          name: 'homepage-down'
          metricNamespace: 'microsoft.insights/components'
          metricName: 'availabilityResults/availabilityPercentage'
          dimensions: [
            {
              name: 'availabilityResult/name'
              operator: 'Include'
              values: ['wilderptsa.net availability']
            }
          ]
          operator: 'LessThan'
          threshold: 1
          timeAggregation: 'Average'
        }
      ]
    }
    actions: [
      { actionGroupId: siteDownGroup.id }
    ]
  }
}

output testId string = availabilityTest.id
output alertId string = siteDownAlert.id
