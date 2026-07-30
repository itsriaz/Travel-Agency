# Travel Agency macOS Launcher

This is a separate macOS packaging path for the existing Travel Agency Operations Electron launcher. It does not replace or modify the Windows portable or NSIS builds.

## Supported Macs

The build target is a Universal application containing both:

- Apple Silicon (`arm64`)
- Intel (`x64`)

## Build Requirements

The final DMG must be built on macOS with:

- Node.js 18 or newer
- npm
- Xcode Command Line Tools (`xcode-select --install`)

## Build the Universal DMG

Copy the project or at least the complete `launcher` directory to a Mac. From Terminal:

```bash
cd launcher
bash scripts/build_mac.sh
```

The installer is created in `launcher/dist-mac`.

## Install on the User's Mac

1. Open the generated `.dmg`.
2. Drag **Travel Agency Operations** into **Applications**.
3. Open it from Applications.

An unsigned development build may be blocked by Gatekeeper. For an internal test build, Control-click the application, choose **Open**, and confirm once. For normal client distribution, sign and notarize the build with an Apple Developer ID certificate.

## Signing and Notarization

Signing does not change the application code. Configure the Apple signing identity and notarization credentials in the Mac build environment before running `build_mac.sh`. Electron Builder will discover an available Developer ID Application certificate from the macOS keychain.

## Mac-specific Configuration

The Mac package embeds `launcher-config.mac.json` as `launcher-config.json`. It points to the same production server and uses `Branch macOS Launcher` as its device label. Windows continues to package the existing Windows configuration.

## Verification Checklist

- Application opens and reaches `https://app.imdadint.ae`.
- Login and two-factor authentication work.
- Offline screen appears when the server cannot be reached.
- Emergency snapshot and draft synchronization work.
- Receipt/PDF windows open normally.
- Ledger PDF saves under the user's Documents directory.
- External WhatsApp links open in the default browser.
