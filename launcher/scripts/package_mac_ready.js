#!/usr/bin/env node

'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const yauzl = require('yauzl');
const archiver = require('archiver');
const { createPackage } = require('@electron/asar');
const { downloadArtifact } = require('@electron/get');

const launcherRoot = path.resolve(__dirname, '..');
const outputRoot = path.join(launcherRoot, 'dist-mac-ready');
const cacheRoot = path.join(launcherRoot, '.electron-cache');
const productName = 'Travel Agency Operations';
const bundleIdentifier = 'com.imdadnobleroute.travelops';
const electronVersion = require(path.join(launcherRoot, 'node_modules/electron/package.json')).version;
const launcherVersion = require(path.join(launcherRoot, 'package.json')).version;

const appFiles = [
  'main.js',
  'preload.js',
  'offline.html',
  'loading.html',
  'renderer.js',
  'styles.css',
  'launcher_gate_private.pem',
];

function copyDirectory(source, destination) {
  fs.mkdirSync(destination, { recursive: true });
  for (const item of fs.readdirSync(source, { withFileTypes: true })) {
    const from = path.join(source, item.name);
    const to = path.join(destination, item.name);
    if (item.isDirectory()) {
      copyDirectory(from, to);
    } else if (item.isFile()) {
      fs.copyFileSync(from, to);
    }
  }
}

function createIcns(sourcePng, destination) {
  const png = fs.readFileSync(sourcePng);
  const chunkLength = 8 + png.length;
  const output = Buffer.alloc(8 + chunkLength);
  output.write('icns', 0, 4, 'ascii');
  output.writeUInt32BE(output.length, 4);
  output.write('ic10', 8, 4, 'ascii');
  output.writeUInt32BE(chunkLength, 12);
  png.copy(output, 16);
  fs.writeFileSync(destination, output);
}

function macInfoPlist(original) {
  return original
    .replace('<string>Electron</string>', `<string>${productName}</string>`)
    .replace('<string>Electron</string>', `<string>${productName}</string>`)
    .replace('<string>Electron</string>', `<string>${productName}</string>`)
    .replace('<string>com.github.Electron</string>', `<string>${bundleIdentifier}</string>`)
    .replace(`<string>${electronVersion}</string>`, `<string>${launcherVersion}</string>`)
    .replace(`<string>${electronVersion}</string>`, `<string>${launcherVersion}</string>`)
    .replace('public.app-category.developer-tools', 'public.app-category.business');
}

async function buildAsar(workRoot) {
  const appSource = path.join(workRoot, 'app-source');
  fs.mkdirSync(appSource, { recursive: true });

  for (const file of appFiles) {
    fs.copyFileSync(path.join(launcherRoot, file), path.join(appSource, file));
  }
  copyDirectory(path.join(launcherRoot, 'assets'), path.join(appSource, 'assets'));
  fs.copyFileSync(
    path.join(launcherRoot, 'launcher-config.mac.json'),
    path.join(appSource, 'launcher-config.json')
  );
  fs.writeFileSync(
    path.join(appSource, 'package.json'),
    JSON.stringify({
      name: 'travel-agency-launcher',
      version: launcherVersion,
      description: 'macOS desktop launcher for Travel Agency Operations',
      main: 'main.js',
      private: true,
    }, null, 2) + '\n'
  );

  const asarPath = path.join(workRoot, 'app.asar');
  await createPackage(appSource, asarPath);
  return asarPath;
}

function readEntry(zip, entry) {
  return new Promise((resolve, reject) => {
    zip.openReadStream(entry, (error, stream) => {
      if (error) {
        reject(error);
        return;
      }
      const chunks = [];
      stream.on('data', (chunk) => chunks.push(chunk));
      stream.on('error', reject);
      stream.on('end', () => resolve(Buffer.concat(chunks)));
    });
  });
}

function shouldSkip(entryName) {
  return entryName.includes('/Contents/_CodeSignature/')
    || entryName.endsWith('/Contents/CodeResources');
}

async function packageRuntime(runtimeZip, architecture, appAsar, iconPath) {
  const architectureLabel = architecture === 'arm64' ? 'Apple-Silicon' : 'Intel';
  const outputZip = path.join(outputRoot, `Travel-Agency-Operations-macOS-${architectureLabel}.zip`);
  const appRoot = `${productName}.app/`;
  const installGuide = [
    'TRAVEL AGENCY OPERATIONS - MAC INSTALLATION',
    '',
    `This package is for ${architecture === 'arm64' ? 'Apple Silicon (M1/M2/M3/M4)' : 'Intel'} Macs.`,
    '',
    '1. Double-click “Install Travel Agency Operations.command”.',
    '2. Enter the Mac login password if macOS requests permission.',
    '3. The launcher will be installed in Applications and opened automatically.',
    '',
    'If macOS blocks the installer the first time, Control-click the installer,',
    'choose Open, and confirm Open once.',
    '',
    'The application connects securely to https://app.imdadint.ae.',
    '',
  ].join('\n');
  const installer = `#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SOURCE_APP="$SCRIPT_DIR/${productName}.app"
DESTINATION_APP="/Applications/${productName}.app"

if [[ ! -d "$SOURCE_APP" ]]; then
  /usr/bin/osascript -e 'display alert "Installation failed" message "Travel Agency Operations.app was not found beside this installer." as critical'
  exit 1
fi

/usr/bin/xattr -dr com.apple.quarantine "$SOURCE_APP" 2>/dev/null || true
/usr/bin/codesign --force --deep --sign - "$SOURCE_APP"

/usr/bin/osascript <<APPLESCRIPT
do shell script "/bin/rm -rf " & quoted form of "$DESTINATION_APP" & "; /usr/bin/ditto " & quoted form of "$SOURCE_APP" & " " & quoted form of "$DESTINATION_APP" with administrator privileges
APPLESCRIPT

/usr/bin/xattr -dr com.apple.quarantine "$DESTINATION_APP" 2>/dev/null || true
/usr/bin/open "$DESTINATION_APP"
/usr/bin/osascript -e 'display notification "Installed and opened successfully." with title "Travel Agency Operations"'
`;

  await new Promise((resolve, reject) => {
    yauzl.open(runtimeZip, { lazyEntries: true }, (openError, zip) => {
      if (openError) {
        reject(openError);
        return;
      }

      const output = fs.createWriteStream(outputZip);
      const archive = archiver('zip', { zlib: { level: 9 } });
      output.on('close', resolve);
      output.on('error', reject);
      archive.on('error', reject);
      archive.pipe(output);

      zip.on('error', reject);
      zip.on('entry', async (entry) => {
        try {
          const originalName = entry.fileName;
          if (shouldSkip(originalName)) {
            zip.readEntry();
            return;
          }

          let targetName = originalName.replace(/^Electron\.app\//, appRoot);
          if (targetName === `${appRoot}Contents/MacOS/Electron`) {
            targetName = `${appRoot}Contents/MacOS/${productName}`;
          }

          const mode = (entry.externalFileAttributes >>> 16) & 0xffff;
          const type = mode & 0xf000;
          if (originalName.endsWith('/')) {
            archive.append('', { name: targetName, type: 'directory', mode: mode & 0xfff || 0o755 });
          } else {
            let body = await readEntry(zip, entry);
            if (originalName === 'Electron.app/Contents/Info.plist') {
              body = Buffer.from(macInfoPlist(body.toString('utf8')), 'utf8');
            } else if (originalName === 'Electron.app/Contents/Resources/electron.icns') {
              body = fs.readFileSync(iconPath);
            }

            if (type === 0xa000) {
              archive.symlink(targetName, body.toString('utf8'), mode & 0xfff || 0o755);
            } else {
              archive.append(body, { name: targetName, mode: mode & 0xfff || 0o644 });
            }
          }
          zip.readEntry();
        } catch (error) {
          reject(error);
        }
      });
      zip.on('end', () => {
        archive.file(appAsar, { name: `${appRoot}Contents/Resources/app.asar`, mode: 0o644 });
        archive.append(installer, { name: 'Install Travel Agency Operations.command', mode: 0o755 });
        archive.append(installGuide, { name: 'INSTALL ON MAC.txt', mode: 0o644 });
        archive.finalize();
      });
      zip.readEntry();
    });
  });

  return outputZip;
}

function sha256(file) {
  return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
}

function packageCombinedInstaller(builds) {
  const outputZip = path.join(outputRoot, 'Travel-Agency-Operations-macOS-Ready-Installer.zip');
  const appleSiliconPackage = path.basename(builds.find((file) => file.includes('Apple-Silicon')));
  const intelPackage = path.basename(builds.find((file) => file.includes('Intel')));
  const installer = `#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MACHINE_ARCH="$(/usr/bin/uname -m)"

case "$MACHINE_ARCH" in
  arm64)
    PACKAGE_NAME="${appleSiliconPackage}"
    ;;
  x86_64)
    PACKAGE_NAME="${intelPackage}"
    ;;
  *)
    /usr/bin/osascript -e 'display alert "Unsupported Mac" message "This Mac architecture is not supported by this launcher." as critical'
    exit 1
    ;;
esac

WORK_DIR="$(/usr/bin/mktemp -d /tmp/travel-agency-installer.XXXXXX)"
cleanup() {
  /bin/rm -rf "$WORK_DIR"
}
trap cleanup EXIT

/usr/bin/ditto -x -k "$SCRIPT_DIR/.payload/$PACKAGE_NAME" "$WORK_DIR"
/bin/bash "$WORK_DIR/Install Travel Agency Operations.command"
`;
  const guide = [
    'TRAVEL AGENCY OPERATIONS - READY MAC INSTALLER',
    '',
    'Double-click “Install Travel Agency Operations.command”.',
    'The installer automatically detects Apple Silicon or Intel.',
    'Enter the Mac login password if requested.',
    '',
    'If macOS blocks it, Control-click the installer, choose Open, and confirm once.',
    '',
  ].join('\n');

  return new Promise((resolve, reject) => {
    const output = fs.createWriteStream(outputZip);
    const archive = archiver('zip', { store: true });
    output.on('close', () => resolve(outputZip));
    output.on('error', reject);
    archive.on('error', reject);
    archive.pipe(output);
    for (const build of builds) {
      archive.file(build, { name: `.payload/${path.basename(build)}`, mode: 0o644 });
    }
    archive.append(installer, { name: 'Install Travel Agency Operations.command', mode: 0o755 });
    archive.append(guide, { name: 'READ ME - INSTALLATION.txt', mode: 0o644 });
    archive.finalize();
  });
}

async function main() {
  fs.mkdirSync(outputRoot, { recursive: true });
  const workRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'travel-agency-mac-ready-'));

  try {
    const appAsar = await buildAsar(workRoot);
    const iconPath = path.join(workRoot, 'app-icon.icns');
    createIcns(path.join(launcherRoot, 'assets/app-icon-mac-1024.png'), iconPath);

    const builds = [];
    for (const architecture of ['arm64', 'x64']) {
      console.log(`Preparing official Electron ${electronVersion} runtime for ${architecture}...`);
      const runtimeZip = await downloadArtifact({
        version: electronVersion,
        artifactName: 'electron',
        platform: 'darwin',
        arch: architecture,
        cacheRoot,
      });
      builds.push(await packageRuntime(runtimeZip, architecture, appAsar, iconPath));
    }

    builds.push(await packageCombinedInstaller(builds));

    const checksums = builds
      .map((file) => `${sha256(file)}  ${path.basename(file)}`)
      .join('\n') + '\n';
    fs.writeFileSync(path.join(outputRoot, 'SHA256SUMS.txt'), checksums);

    console.log('\nReady macOS launcher packages:');
    for (const file of builds) {
      console.log(` - ${file}`);
    }
  } finally {
    fs.rmSync(workRoot, { recursive: true, force: true });
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
