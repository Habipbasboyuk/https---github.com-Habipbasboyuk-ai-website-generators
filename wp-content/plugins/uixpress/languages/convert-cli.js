const fs = require("fs");
const path = require("path");
const { execSync } = require("child_process");

// run with command: node convert-cli.js

// Function to convert a single .po file
function convertPoToJson(inputFile) {
  const match = inputFile.match(/(.+)-(.+)\.po$/);
  if (!match) {
    console.error(`Invalid file name format: ${inputFile}`);
    return;
  }

  const [, textDomain, lang] = match;
  const outputFile = `${path.parse(inputFile).name}-${textDomain}.json`;
  const tempFile = `temp_${Math.random().toString(36).substring(7)}.json`;

  try {
    execSync(`i18next-conv -l ${lang} -s ${inputFile} -t ${tempFile}`);
    let data = JSON.parse(fs.readFileSync(tempFile, "utf8"));
    let wpFormat = {
      domain: textDomain,
      locale_data: {
        [textDomain]: {
          "": {
            domain: textDomain,
            lang: lang,
            "plural-forms": "nplurals=2; plural=(n != 1);",
          },
        },
      },
    };

    Object.entries(data).forEach(([key, value]) => {
      if (key !== "") {
        wpFormat.locale_data[textDomain][key] = [value];
      }
    });

    fs.writeFileSync(outputFile, JSON.stringify(wpFormat, null, 2));
    console.log(`Conversion completed. Output saved to ${outputFile}`);
  } catch (error) {
    console.error(`An error occurred while processing ${inputFile}:`, error.message);
  } finally {
    if (fs.existsSync(tempFile)) {
      fs.unlinkSync(tempFile);
    }
  }
}

// Get all .po files in the current directory
const poFiles = fs.readdirSync(".").filter((file) => file.endsWith(".po"));

if (poFiles.length === 0) {
  console.log("No .po files found in the current directory.");
} else {
  console.log(`Found ${poFiles.length} .po file(s). Processing...`);
  poFiles.forEach(convertPoToJson);
}
