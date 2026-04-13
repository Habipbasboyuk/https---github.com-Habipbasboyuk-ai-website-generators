import fs from "fs";
import path from "path";
import { execSync } from "child_process";
import { fileURLToPath } from "url";
import gettextParser from "gettext-parser";
import { Translate } from "@google-cloud/translate/build/src/v2/index.js";
import dotenv from "dotenv";

// Load environment variables
dotenv.config();

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Configuration
const TEXT_DOMAIN = "uixpress";
const POT_FILE = path.join(__dirname, `${TEXT_DOMAIN}.pot`);
const BATCH_SIZE = 50; // Smaller batches to avoid rate limits
const DELAY_BETWEEN_BATCHES = 1000; // 1 second delay between batches
const MAX_RETRIES = 3;

/**
 * Sleep for a given number of milliseconds
 */
function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// Supported languages - Google Translate language codes
const LANGUAGES = {
  cs_CZ: { code: "cs", name: "Czech (Czech Republic)" },
  de_DE: { code: "de", name: "German (Germany)" },
  es_ES: { code: "es", name: "Spanish (Spain)" },
  fr_FR: { code: "fr", name: "French (France)" },
  it_IT: { code: "it", name: "Italian (Italy)" },
  ja: { code: "ja", name: "Japanese" },
  ko_KR: { code: "ko", name: "Korean (South Korea)" },
  nl_NL: { code: "nl", name: "Dutch (Netherlands)" },
  pl_PL: { code: "pl", name: "Polish (Poland)" },
  pt_BR: { code: "pt", name: "Portuguese (Brazil)" },
  pt_PT: { code: "pt-PT", name: "Portuguese (Portugal)" },
  ru_RU: { code: "ru", name: "Russian (Russia)" },
  tr_TR: { code: "tr", name: "Turkish (Turkey)" },
  zh_CN: { code: "zh-CN", name: "Chinese (Simplified)" },
  zh_TW: { code: "zh-TW", name: "Chinese (Traditional)" },
};

// Initialize Google Translate client
const translate = new Translate({
  key: process.env.GOOGLE_TRANSLATE_API_KEY,
});

/**
 * Parse a POT or PO file and return the parsed data
 */
function parsePoFile(filePath) {
  const content = fs.readFileSync(filePath);
  return gettextParser.po.parse(content);
}

/**
 * Extract msgid strings from parsed PO data
 */
function extractMsgIds(poData) {
  const translations = poData.translations[""] || {};
  const msgIds = new Map();

  for (const [msgid, entry] of Object.entries(translations)) {
    if (msgid === "") continue; // Skip header
    msgIds.set(msgid, entry);
  }

  return msgIds;
}

/**
 * Find strings that need translation (new or empty translations)
 */
function findStringsToTranslate(potMsgIds, existingPoData) {
  const toTranslate = [];
  const existingTranslations = existingPoData?.translations?.[""] || {};

  for (const [msgid, potEntry] of potMsgIds) {
    const existing = existingTranslations[msgid];

    // Translate if: doesn't exist, or msgstr is empty
    if (!existing || !existing.msgstr || existing.msgstr[0] === "") {
      toTranslate.push({
        msgid,
        comments: potEntry.comments,
      });
    }
  }

  return toTranslate;
}

/**
 * Translate a single batch with retry logic
 */
async function translateBatchWithRetry(stringsToTranslate, targetLangCode, retryCount = 0) {
  try {
    const [results] = await translate.translate(stringsToTranslate, {
      from: "en",
      to: targetLangCode,
      format: "text",
    });
    return Array.isArray(results) ? results : [results];
  } catch (error) {
    if (retryCount < MAX_RETRIES && error.message.includes("Rate Limit")) {
      const delay = Math.pow(2, retryCount + 1) * 1000; // Exponential backoff: 2s, 4s, 8s
      console.log(`    Rate limited. Waiting ${delay / 1000}s before retry ${retryCount + 1}/${MAX_RETRIES}...`);
      await sleep(delay);
      return translateBatchWithRetry(stringsToTranslate, targetLangCode, retryCount + 1);
    }
    throw error;
  }
}

/**
 * Translate strings using Google Cloud Translation API
 */
async function translateStrings(strings, targetLangCode, langName) {
  if (strings.length === 0) return {};

  const translations = {};

  // Process in batches
  for (let i = 0; i < strings.length; i += BATCH_SIZE) {
    const batch = strings.slice(i, i + BATCH_SIZE);
    const batchNum = Math.floor(i / BATCH_SIZE) + 1;
    const totalBatches = Math.ceil(strings.length / BATCH_SIZE);

    console.log(
      `    Translating batch ${batchNum}/${totalBatches} (${batch.length} strings)...`
    );

    const stringsToTranslate = batch.map((s) => s.msgid);

    try {
      const translatedArray = await translateBatchWithRetry(stringsToTranslate, targetLangCode);

      // Map results back to original strings
      for (let j = 0; j < stringsToTranslate.length; j++) {
        translations[stringsToTranslate[j]] = translatedArray[j];
      }
    } catch (error) {
      console.error(`    Error translating batch: ${error.message}`);
      // Continue with other batches even if one fails
    }

    // Add delay between batches to avoid rate limiting
    if (i + BATCH_SIZE < strings.length) {
      await sleep(DELAY_BETWEEN_BATCHES);
    }
  }

  return translations;
}

/**
 * Create or update a PO file with new translations
 */
function createPoFile(potData, existingPoData, newTranslations, langCode) {
  // Clone the POT structure
  const poData = JSON.parse(JSON.stringify(potData));

  // Update headers for the target language
  const headers = poData.translations[""][""];
  headers.msgstr[0] = headers.msgstr[0]
    .replace("LANGUAGE <LL@li.org>", `${LANGUAGES[langCode].name}`)
    .replace("Language: \\n", `Language: ${langCode.replace("_", "-")}\\n`)
    .replace(
      "PO-Revision-Date: ",
      `PO-Revision-Date: ${new Date().toISOString()}\\n`
    );

  // Get existing translations
  const existingTranslations = existingPoData?.translations?.[""] || {};

  // Apply translations
  for (const msgid of Object.keys(poData.translations[""])) {
    if (msgid === "") continue;

    // Check for new translation first, then existing translation
    if (newTranslations[msgid]) {
      poData.translations[""][msgid].msgstr = [newTranslations[msgid]];
    } else if (
      existingTranslations[msgid] &&
      existingTranslations[msgid].msgstr[0]
    ) {
      poData.translations[""][msgid].msgstr = existingTranslations[msgid].msgstr;
    }
  }

  return poData;
}

/**
 * Write PO file to disk
 */
function writePoFile(poData, outputPath) {
  const output = gettextParser.po.compile(poData);
  fs.writeFileSync(outputPath, output);
}

/**
 * Generate MO file from PO file using msgfmt
 */
function generateMoFile(poPath, moPath) {
  try {
    execSync(`msgfmt -o "${moPath}" "${poPath}"`, { stdio: "pipe" });
    return true;
  } catch (error) {
    console.warn(
      `    Warning: Could not generate .mo file. Is gettext/msgfmt installed?`
    );
    console.warn(`    Install with: brew install gettext (macOS)`);
    return false;
  }
}

/**
 * Generate WordPress JSON file from PO data
 * Based on the existing convert-cli.js logic
 */
function generateJsonFile(poData, jsonPath, langCode) {
  const wpFormat = {
    domain: TEXT_DOMAIN,
    locale_data: {
      [TEXT_DOMAIN]: {
        "": {
          domain: TEXT_DOMAIN,
          lang: langCode,
          "plural-forms": "nplurals=2; plural=(n != 1);",
        },
      },
    },
  };

  // Add translations
  for (const [msgid, entry] of Object.entries(poData.translations[""])) {
    if (msgid === "") continue;
    if (entry.msgstr && entry.msgstr[0]) {
      wpFormat.locale_data[TEXT_DOMAIN][msgid] = entry.msgstr;
    }
  }

  fs.writeFileSync(jsonPath, JSON.stringify(wpFormat, null, 2));
}

/**
 * Main function
 */
async function main() {
  console.log("🌍 UIXpress Translation Script (Google Cloud Translation)");
  console.log("===========================================================\n");

  // Verify API key
  if (!process.env.GOOGLE_TRANSLATE_API_KEY) {
    console.error("Error: GOOGLE_TRANSLATE_API_KEY environment variable is not set.");
    console.error("Create a .env file with your API key or set it directly:");
    console.error("  GOOGLE_TRANSLATE_API_KEY=xxx node translate-cli.js");
    console.error("\nGet an API key from: https://console.cloud.google.com/apis/credentials");
    process.exit(1);
  }

  // Verify POT file exists
  if (!fs.existsSync(POT_FILE)) {
    console.error(`Error: POT file not found: ${POT_FILE}`);
    process.exit(1);
  }

  // Parse POT file
  console.log("📖 Parsing POT file...");
  const potData = parsePoFile(POT_FILE);
  const potMsgIds = extractMsgIds(potData);
  console.log(`   Found ${potMsgIds.size} translatable strings\n`);

  // Process each language
  for (const [langCode, langInfo] of Object.entries(LANGUAGES)) {
    console.log(`\n🔤 Processing ${langInfo.name} (${langCode})...`);

    const poPath = path.join(__dirname, `${TEXT_DOMAIN}-${langCode}.po`);
    const moPath = path.join(__dirname, `${TEXT_DOMAIN}-${langCode}.mo`);
    const jsonPath = path.join(
      __dirname,
      `${TEXT_DOMAIN}-${langCode}-${TEXT_DOMAIN}.json`
    );

    // Load existing PO file if it exists
    let existingPoData = null;
    if (fs.existsSync(poPath)) {
      try {
        existingPoData = parsePoFile(poPath);
        console.log("   Loaded existing translations");
      } catch (error) {
        console.warn(`   Warning: Could not parse existing PO file: ${error.message}`);
      }
    }

    // Find strings that need translation
    const stringsToTranslate = findStringsToTranslate(potMsgIds, existingPoData);

    if (stringsToTranslate.length === 0) {
      console.log("   All strings already translated!");
    } else {
      console.log(`   Found ${stringsToTranslate.length} strings to translate`);

      // Translate with Google Cloud Translation
      const newTranslations = await translateStrings(
        stringsToTranslate,
        langInfo.code,
        langInfo.name
      );

      console.log(
        `   Received ${Object.keys(newTranslations).length} translations`
      );

      // Create updated PO data
      const poData = createPoFile(
        potData,
        existingPoData,
        newTranslations,
        langCode
      );

      // Write PO file
      writePoFile(poData, poPath);
      console.log(`   ✓ Written: ${path.basename(poPath)}`);

      // Generate MO file
      if (generateMoFile(poPath, moPath)) {
        console.log(`   ✓ Written: ${path.basename(moPath)}`);
      }

      // Generate JSON file
      generateJsonFile(poData, jsonPath, langCode);
      console.log(`   ✓ Written: ${path.basename(jsonPath)}`);
    }
  }

  console.log("\n✅ Translation complete!");
}

// Run the script
main().catch((error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
