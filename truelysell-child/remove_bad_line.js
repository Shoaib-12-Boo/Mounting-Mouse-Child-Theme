const fs = require('fs');
const filePath = 'functions.php';
const lines = fs.readFileSync(filePath, 'utf8').split(/\r?\n/);
const index = lines.findIndex(line => line.trim() === "</em>';" || line.trim() === '</em>');
if (index !== -1) {
  lines.splice(index, 1);
  fs.writeFileSync(filePath, lines.join('\n'), 'utf8');
  console.log('Removed malformed line at', index);
} else {
  console.log('Malformed line not found');
}
