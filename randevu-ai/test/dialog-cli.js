'use strict';
// Telefon DONANIMI OLMADAN diyalog beynini test et:
//   ANTHROPIC_API_KEY=... node test/dialog-cli.js
// Klavyeden "konus", asistan cevabini gor. ctx.stub=true -> gercek API cagrilmaz,
// gercek randevu OLUSMAZ. Amac: NLU + tarih + hizmet + tool akisini denemek.
const readline = require('readline');
const { Dialog } = require('../src/dialog');

// Ornek cagri baglami (canlida santralkarsilamametni API'sinden gelir).
const callContext = {
  stub: true,
  salonAdi: 'Bercislina Guzellik Salonu',
  salonId: 123,
  userId: 4567,
  musteriAdi: 'Ayse Yilmaz',
  hizmetler: [
    { salonHizmetId: 11, ad: 'Sac Kesimi', sureDk: 30, fiyat: 300, personeller: [{ id: 101, ad: 'Elif' }, { id: 102, ad: 'Merve' }] },
    { salonHizmetId: 12, ad: 'Sac Boyama', sureDk: 90, fiyat: 900, personeller: [{ id: 101, ad: 'Elif' }] },
    { salonHizmetId: 13, ad: 'Manikur', sureDk: 45, fiyat: 250, personeller: [{ id: 103, ad: 'Zeynep' }] },
    { salonHizmetId: 14, ad: 'Protez Tirnak', sureDk: 120, fiyat: 700, personeller: [{ id: 103, ad: 'Zeynep' }] },
  ],
};

async function main() {
  const dialog = new Dialog(callContext);
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });

  // onSentence: her cumle uretildikce akarak yaz (canlida bu an TTS+calma tetiklenir).
  const stream = (s) => process.stdout.write(`\x1b[36m${s}\x1b[0m `);
  const ask = () => rl.question('\n\x1b[33mMUSTERI:\x1b[0m ', onLine);

  process.stdout.write('\x1b[36mASISTAN:\x1b[0m ');
  const opening = await dialog.opening(stream);
  process.stdout.write('\n');
  if (opening.control) return finish(opening.control);
  ask();

  async function onLine(line) {
    process.stdout.write('\x1b[36mASISTAN:\x1b[0m ');
    const res = await dialog.handleUtterance(line, stream);
    process.stdout.write('\n');
    if (res.control) return finish(res.control);
    ask();
  }

  function finish(control) {
    console.log(`\x1b[90m[kontrol: ${control} -> canlida ${control === 'transfer' ? 'operator-bagla' : 'Hangup'}]\x1b[0m`);
    rl.close();
  }
}

main().catch(e => { console.error(e); process.exit(1); });
