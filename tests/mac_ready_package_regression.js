#!/usr/bin/env node

'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const yauzl = require(path.resolve(__dirname, '../launcher/node_modules/yauzl'));
const { extractFile } = require(path.resolve(__dirname, '../launcher/node_modules/@electron/asar'));

const launcherRoot = path.resolve(__dirname, '../launcher');
const outputRoot = path.join(launcherRoot, 'dist-mac-ready');
const appRoot = 'Travel Agency Operations.app/';
const cases = [
  {
    architecture: 'arm64',
    cpuType: 0x0100000c,
    file: 'Travel-Agency-Operations-macOS-Apple-Silicon.zip',
    guideText: 'Apple Silicon',
  },
  {
    architecture: 'x64',
    cpuType: 0x01000007,
    file: 'Travel-Agency-Operations-macOS-Intel.zip',
    guideText: 'Intel',
  },
];

let failed = false;

function check(label, passed) {
  console.log(`${passed ? '[PASS]' : '[FAIL]'} ${label}`);
  if (!passed) failed = true;
}

function sha256(file) {
  return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
}

function inspectZip(file) {
  return new Promise((resolve, reject) => {
    yauzl.open(file, { lazyEntries: true }, (error, zip) => {
      if (error) return reject(error);

      const result = { entries: new Map(), symlinks: 0, signedEntries: 0 };
      const capture = new Set([
        `${appRoot}Contents/MacOS/Travel Agency Operations`,
        `${appRoot}Contents/Info.plist`,
        `${appRoot}Contents/Resources/app.asar`,
        'Install Travel Agency Operations.command',
        'INSTALL ON MAC.txt',
      ]);

      zip.on('error', reject);
      zip.on('entry', (entry) => {
        const mode = (entry.externalFileAttributes >>> 16) & 0xffff;
        if ((mode & 0xf000) === 0xa000) result.symlinks += 1;
        if (entry.fileName.includes('/Contents/_CodeSignature/')) result.signedEntries += 1;

        if (!capture.has(entry.fileName)) {
          zip.readEntry();
          return;
        }

        zip.openReadStream(entry, (streamError, stream) => {
          if (streamError) return reject(streamError);
          const chunks = [];
          stream.on('data', (chunk) => chunks.push(chunk));
          stream.on('error', reject);
          stream.on('end', () => {
            result.entries.set(entry.fileName, { mode, body: Buffer.concat(chunks) });
            zip.readEntry();
          });
        });
      });
      zip.on('end', () => resolve(result));
      zip.readEntry();
    });
  });
}

async function main() {
  const checksumFile = path.join(outputRoot, 'SHA256SUMS.txt');
  const checksums = fs.existsSync(checksumFile) ? fs.readFileSync(checksumFile, 'utf8') : '';

  for (const testCase of cases) {
    const file = path.join(outputRoot, testCase.file);
    check(`${testCase.architecture} ready package exists`, fs.existsSync(file) && fs.statSync(file).size > 50_000_000);
    if (!fs.existsSync(file)) continue;

    const result = await inspectZip(file);
    const executable = result.entries.get(`${appRoot}Contents/MacOS/Travel Agency Operations`);
    const plist = result.entries.get(`${appRoot}Contents/Info.plist`)?.body.toString('utf8') || '';
    const guide = result.entries.get('INSTALL ON MAC.txt')?.body.toString('utf8') || '';
    const installer = result.entries.get('Install Travel Agency Operations.command');
    const asar = result.entries.get(`${appRoot}Contents/Resources/app.asar`);

    check(`${testCase.architecture} executable is present and executable`, Boolean(executable && (executable.mode & 0o111)));
    check(
      `${testCase.architecture} runtime CPU type is correct`,
      Boolean(executable && executable.body.length > 8 && executable.body.readUInt32LE(4) === testCase.cpuType)
    );
    check(`${testCase.architecture} macOS framework symlinks are preserved`, result.symlinks >= 14);
    check(`${testCase.architecture} stale Electron signature seal is removed`, result.signedEntries === 0);
    check(
      `${testCase.architecture} bundle metadata identifies Travel Agency Operations`,
      plist.includes('<string>Travel Agency Operations</string>')
        && plist.includes('<string>com.imdadnobleroute.travelops</string>')
        && plist.includes('public.app-category.business')
    );
    check(`${testCase.architecture} includes a simple matching installation guide`, guide.includes(testCase.guideText));
    check(
      `${testCase.architecture} includes an executable one-click installer`,
      Boolean(
        installer
          && (installer.mode & 0o111)
          && installer.body.toString('utf8').includes('/usr/bin/codesign --force --deep --sign -')
          && installer.body.toString('utf8').includes('/Applications/Travel Agency Operations.app')
      )
    );

    if (asar) {
      const temporaryAsar = path.join(os.tmpdir(), `travel-agency-${testCase.architecture}-${process.pid}.asar`);
      fs.writeFileSync(temporaryAsar, asar.body);
      try {
        const config = JSON.parse(extractFile(temporaryAsar, 'launcher-config.json').toString('utf8'));
        const manifest = JSON.parse(extractFile(temporaryAsar, 'package.json').toString('utf8'));
        check(
          `${testCase.architecture} embeds the production Mac launcher configuration`,
          config.serverUrl === 'https://app.imdadint.ae'
            && config.sourceDevice === 'Branch macOS Launcher'
            && manifest.main === 'main.js'
        );
      } finally {
        fs.rmSync(temporaryAsar, { force: true });
      }
    } else {
      check(`${testCase.architecture} contains the launcher application archive`, false);
    }

    check(`${testCase.architecture} SHA-256 checksum matches`, checksums.includes(`${sha256(file)}  ${testCase.file}`));
  }

  const combinedName = 'Travel-Agency-Operations-macOS-Ready-Installer.zip';
  const combinedPath = path.join(outputRoot, combinedName);
  check('single automatic Mac installer exists', fs.existsSync(combinedPath) && fs.statSync(combinedPath).size > 180_000_000);
  if (fs.existsSync(combinedPath)) {
    const combined = await new Promise((resolve, reject) => {
      yauzl.open(combinedPath, { lazyEntries: true }, (error, zip) => {
        if (error) return reject(error);
        const entries = new Map();
        zip.on('error', reject);
        zip.on('entry', (entry) => {
          if (entry.fileName !== 'Install Travel Agency Operations.command') {
            entries.set(entry.fileName, { mode: (entry.externalFileAttributes >>> 16) & 0xffff });
            zip.readEntry();
            return;
          }
          zip.openReadStream(entry, (streamError, stream) => {
            if (streamError) return reject(streamError);
            const chunks = [];
            stream.on('data', (chunk) => chunks.push(chunk));
            stream.on('error', reject);
            stream.on('end', () => {
              entries.set(entry.fileName, {
                mode: (entry.externalFileAttributes >>> 16) & 0xffff,
                body: Buffer.concat(chunks),
              });
              zip.readEntry();
            });
          });
        });
        zip.on('end', () => resolve(entries));
        zip.readEntry();
      });
    });
    const automaticInstaller = combined.get('Install Travel Agency Operations.command');
    const installerText = automaticInstaller?.body?.toString('utf8') || '';
    check(
      'single installer bundles both Mac architectures',
      combined.has('.payload/Travel-Agency-Operations-macOS-Apple-Silicon.zip')
        && combined.has('.payload/Travel-Agency-Operations-macOS-Intel.zip')
    );
    check(
      'single installer automatically selects the correct architecture',
      Boolean(
        automaticInstaller
          && (automaticInstaller.mode & 0o111)
          && installerText.includes('MACHINE_ARCH="$(/usr/bin/uname -m)"')
          && installerText.includes('arm64)')
          && installerText.includes('x86_64)')
          && installerText.includes('$SCRIPT_DIR/.payload/$PACKAGE_NAME')
      )
    );
    check('single installer SHA-256 checksum matches', checksums.includes(`${sha256(combinedPath)}  ${combinedName}`));
  }

  if (failed) process.exit(1);
  console.log('\nReady macOS launcher package regression passed.');
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
