const fs = require('fs');

let code = fs.readFileSync('src/Admin/PageRenderer.php', 'utf8');

const regex = /<form method="post" action="options\.php" autocomplete="off" class="pt-mo-settings-form">\s*<\?php\s*settings_fields\(MEDIA_OFFLOAD_SETTINGS_GROUP\);\s*\$this->settingsRegistrar->renderSections\(\$this->settingsRegistrar->getSectionIdsForTab\('logs'\)\);\s*submit_button\(__\('Save Log Settings', MEDIA_OFFLOAD_TEXT_DOMAIN\)\);\s*\?>\s*<\/form>/g;

code = code.replace(regex, '');

fs.writeFileSync('src/Admin/PageRenderer.php', code);
