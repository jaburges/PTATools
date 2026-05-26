# PTSA Board Xcode Cloud / TestFlight Setup

This app is ready for Xcode Cloud from the repo side. Xcode Cloud still needs
an App Store Connect app/product and workflow because those settings live in
Apple, not in git.

## App Store Connect App

- App name: `PTA Tools`
- Bundle ID: `com.burgess.PTAtools`
- SKU: `6773496257`
- Primary language: English
- Platform: iOS

## Repository / Project

- Repository: `jaburges/PTATools`
- Project path: `ios-app/PTSABoard.xcodeproj`
- Scheme: `PTSABoard`
- Xcode version: Latest Release
- macOS version: Latest Release
- Clean build: Enabled

The `PTSABoard` scheme is shared in:

```text
ios-app/PTSABoard.xcodeproj/xcshareddata/xcschemes/PTSABoard.xcscheme
```

## Recommended Workflow

Name:

```text
Archive to TestFlight
```

Start condition:

```text
Branch: main
Auto-cancel: enabled
```

Action:

```text
Archive - iOS
Scheme: PTSABoard
Platform: iOS
Distribution: Internal Testing / TestFlight internal testers
Required to pass: yes
```

Xcode Cloud action payload shape (matching existing account workflows):

```json
[
  {
    "name": "Archive - iOS",
    "actionType": "ARCHIVE",
    "platform": "IOS",
    "scheme": "PTSABoard",
    "destination": null,
    "buildDistributionAudience": "INTERNAL_ONLY",
    "isRequiredToPass": true,
    "testConfiguration": null
  }
]
```

## Repo-Side CI Scripts

Xcode Cloud runs scripts from `ios-app/ci_scripts/` because that directory is
next to `PTSABoard.xcodeproj`.

- `ci_post_clone.sh`
  - Tunes git for the MSAL SwiftPM checkout.
  - Resolves Swift packages before the build.
- `ci_pre_xcodebuild.sh`
  - Fails early if `AppConfig.swift` contains placeholders.
  - Verifies the bundle ID is `com.burgess.PTAtools`.
- `ci_post_xcodebuild.sh`
  - Prints build metadata and archive path for diagnostics.

## Signing

The project uses automatic signing:

```text
DEVELOPMENT_TEAM = X25R9XDCN3
CODE_SIGN_STYLE = Automatic
PRODUCT_BUNDLE_IDENTIFIER = com.burgess.PTAtools
```

Make sure the App Store Connect app, bundle ID, and team all belong to the
same Apple Developer account. Xcode Cloud will manage signing assets when the
workflow is connected to that team.

## Microsoft Sign-In

The Entra iOS app registration must continue to include this redirect URI:

```text
msauth.com.burgess.PTAtools://auth
```

The iOS app client ID and tenant ID are public identifiers in
`PTSABoard/Config/AppConfig.swift`; they are not secrets.
