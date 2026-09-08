#!/usr/bin/env node
'use strict';
/**
 * NIYET DENEME ARACI — sozluk (intents.json) duzenledikten sonra test icin.
 * Kullanim:
 *   node src/niyet-dene.js "en yakin randevum ne zaman"
 *   node src/niyet-dene.js            (ornek bir dizi cumleyi topluca dener)
 * Cikti: her cumle icin bulunan niyet. JSON bozuksa uyari verir.
 */
const { niyetBul, fold } = require('./rulesNlu');

const ornekler = [
  'randevumu iptal etmek istiyorum',
  'randevumu baska gune alalim',
  'adresiniz nerede',
  'isletmeye baglar misiniz',
  'hangi hizmetleri veriyorsunuz',
  'yarin musait misiniz',
  'borcum ne kadar',
  'en yakin randevum ile ilgili bilgilendirme',
  'randevu almak istiyorum',
];

const arg = process.argv.slice(2).join(' ').trim();
const liste = arg ? [arg] : ornekler;

for (const c of liste) {
  console.log(`${niyetBul(c).padEnd(10)} <- "${c}"   (fold: ${fold(c)})`);
}
