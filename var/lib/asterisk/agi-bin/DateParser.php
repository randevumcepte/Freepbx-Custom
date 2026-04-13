    <?php

    class DateParser
    {
        private $now;

        private $trMonths = [
            'ocak' => 1, 'şubat' => 2, 'mart' => 3, 'nisan' => 4, 'mayıs' => 5, 'haziran' => 6,
            'temmuz' => 7, 'ağustos' => 8, 'eylül' => 9, 'ekim' => 10, 'kasım' => 11, 'aralık' => 12
        ];

        private $trDays = [
            'pazartesi' => 1, 'salı' => 2, 'çarşamba' => 3, 'perşembe' => 4,
            'cuma' => 5, 'cumartesi' => 6, 'pazar' => 7
        ];

        public function __construct()
        {
            $this->now = new DateTime('now', new DateTimeZone('Europe/Istanbul'));
        }

        /* -----------------------------------------------------------
         * MAIN
         * -----------------------------------------------------------*/

        public function parseTurkishDate($text)
    {
        $text = $this->normalizeSpeech($text);
        $text = mb_strtolower(trim($text), 'UTF-8');

        // 🔥 GÜN HER ZAMAN ÖNCE
        if ($this->isDayOfWeek($text)) {
            return $this->parseDayOfWeek($text);
        }

        if ($this->isToday($text)) {
            return $this->parseToday($text);
        }

        if ($this->isTomorrow($text)) {
            return $this->parseTomorrow($text);
        }

        if ($this->isRelativeWeek($text)) {
            return $this->parseRelativeWeek($text);
        }

        if ($this->isDateWithMonth($text)) {
            return $this->parseDateWithMonth($text);
        }

        return $this->parseGeneric($text);
    }


        /* -----------------------------------------------------------
         * STT NORMALIZE
         * -----------------------------------------------------------*/

        private function normalizeSpeech($text)
        {
            $text = mb_strtolower($text, 'UTF-8');

            // gereksiz kelimeler
            $text = str_replace(
                ['günü','gunu','saat','saatte','gibi','civarı','icin','için','olan','bir'],
                '',
                $text
            );

            // çekim ekleri
            $text = str_replace(
                ['pazara','pazari','cumaya','cumayi','cumartesiye'],
                ['pazar','pazar','cuma','cuma','cumartesi'],
                $text
            );

            // konuşma sayıları
            $numbers = [
                'bir'=>1,'iki'=>2,'üç'=>3,'dört'=>4,'beş'=>5,'altı'=>6,'yedi'=>7,'sekiz'=>8,'dokuz'=>9,
                'on'=>10,'on bir'=>11,'on iki'=>12,'on üç'=>13,'on dört'=>14,'on beş'=>15,
                'on altı'=>16,'on yedi'=>17,'on sekiz'=>18,'on dokuz'=>19,'yirmi'=>20
            ];

            foreach ($numbers as $word=>$num) {
                $text = str_replace($word, $num, $text);
            }

            // 16 30 → 16:30
            $text = preg_replace('/(\d{1,2})\s+(\d{2})/', '$1:$2', $text);

            return trim($text);
        }

        /* -----------------------------------------------------------
         * TODAY / TOMORROW
         * -----------------------------------------------------------*/

        private function isToday($text)
        {
            return preg_match('/bugün|bu gün/u', $text);
        }

        private function parseToday($text)
        {
            $date = clone $this->now;
            $time = $this->extractTime($text);
            return $this->applyTimeToDate($date, $time);
        }

        private function isTomorrow($text)
        {
            return preg_match('/yarın|yarin/u', $text);
        }

        private function parseTomorrow($text)
        {
            $date = clone $this->now;
            $date->modify('+1 day');
            $time = $this->extractTime($text);
            return $this->applyTimeToDate($date, $time);
        }

        /* -----------------------------------------------------------
         * DAY OF WEEK
         * -----------------------------------------------------------*/

        private function isDayOfWeek($text)
        {
            return preg_match('/\b(pazartesi|salı|çarşamba|perşembe|cuma|cumartesi|pazar)\b/u', $text);
        }

        private function parseDayOfWeek($text)
        {
            $date = clone $this->now;

            preg_match('/\b(pazartesi|salı|çarşamba|perşembe|cuma|cumartesi|pazar)\b/u', $text, $m);
            $dayName = $m[1] ?? null;

            if (!$dayName) {
                return $this->parseGeneric($text);
            }

            $targetDay = $this->trDays[$dayName];
            $currentDay = (int)$date->format('N');

            $dayDiff = $targetDay - $currentDay;
            if ($dayDiff <= 0) {
                $dayDiff += 7;
            }

            if (strpos($text, 'gelecek') !== false || strpos($text, 'önümüzdeki') !== false) {
                $dayDiff += 7;
            }

            $date->modify("+{$dayDiff} days");

            $time = $this->extractTime($text);
            return $this->applyTimeToDate($date, $time);
        }

        /* -----------------------------------------------------------
         * RELATIVE WEEK
         * -----------------------------------------------------------*/

        private function isRelativeWeek($text)
        {
            return preg_match('/gelecek hafta|önümüzdeki hafta|haftaya/u', $text);
        }

        private function parseRelativeWeek($text)
        {
            $date = clone $this->now;
            $date->modify('next monday');

            foreach ($this->trDays as $day => $num) {
                if (strpos($text, $day) !== false) {
                    $date->modify("+".($num-1)." days");
                    break;
                }
            }

            $time = $this->extractTime($text);
            return $this->applyTimeToDate($date, $time);
        }

        /* -----------------------------------------------------------
         * DATE WITH MONTH
         * -----------------------------------------------------------*/

        private function isDateWithMonth($text)
        {
            foreach ($this->trMonths as $month => $value) {
                if (strpos($text, $month) !== false) {
                    return true;
                }
            }
            return false;
        }

        private function parseDateWithMonth($text)
        {
            $year = (int)$this->now->format('Y');

            // Ayı bul
            foreach ($this->trMonths as $monthName => $monthNumber) {
                if (strpos($text, $monthName) !== false) {
                    $month = $monthNumber;
                    break;
                }
            }

            // Günü bul (ilk sayı)
            preg_match('/^(\d{1,2})/', $text, $m);
            $day = isset($m[1]) ? (int)$m[1] : 1;

            // Yıl kontrolü (geçmişse gelecek yıl)
            $currentMonth = (int)$this->now->format('n');
            $currentDay = (int)$this->now->format('j');
            
            if ($month < $currentMonth || ($month == $currentMonth && $day < $currentDay)) {
                $year++;
            }

            $date = DateTime::createFromFormat('Y-n-j', "$year-$month-$day");
            $time = $this->extractTime($text);
            
            return $this->applyTimeToDate($date, $time);
        }

        /* -----------------------------------------------------------
         * TIME PARSER (EN KRİTİK KISIM)
         * -----------------------------------------------------------*/

        private function extractTime($text)
        {
            $hour = null;
            $minute = 0;
            $period = null; // sabah, öğle, öğleden sonra, akşam, gece

            // 🚨 1. "buçuk/yarım" kontrolü (her zaman önce!)
            if (strpos($text, 'buçuk') !== false || strpos($text, 'yarım') !== false) {
                $minute = 30;
            }

            // 2. "çeyrek" kontrolü
            if (strpos($text, 'çeyrek') !== false) {
                $minute = 15;
            }

            // 3. "3'te", "5'de", "10'da" formatı
            if (preg_match('/(\d{1,2})[\'’]?(de|da|te|ta)/u', $text, $m)) {
                $hour = (int)$m[1];
            }
            // 4. "saat 3" formatı
            elseif (preg_match('/saat\s*(\d{1,2})/u', $text, $m)) {
                $hour = (int)$m[1];
            }
            // 5. "14:30" formatı
            elseif (preg_match('/(\d{1,2}):(\d{2})/', $text, $m)) {
                $hour = (int)$m[1];
                $minute = (int)$m[2];
            }
            // 6. "3 30" formatı
            elseif (preg_match('/(\d{1,2})\s+(\d{2})/', $text, $m)) {
                $hour = (int)$m[1];
                $minute = (int)$m[2];
            }
            // 7. METNİN SONUNDAKİ SAYI (son çare)
            elseif (preg_match('/(\d{1,2})$/', $text, $m)) {
                $hour = (int)$m[1];
            }

            // 🚨 ZAMAN DİLİMİ ANALİZİ
            if (strpos($text, 'sabah') !== false) $period = 'morning';
            if (strpos($text, 'öğle') !== false) $period = 'noon';
            if (strpos($text, 'öğleden sonra') !== false) $period = 'afternoon';
            if (strpos($text, 'akşam') !== false) $period = 'evening';
            if (strpos($text, 'gece') !== false) $period = 'night';

            // 🚨 ZAMAN DİLİMİNE GÖRE SAAT DÜZELT
            if ($hour !== null) {
                if ($period == 'afternoon' || $period == 'evening') {
                    if ($hour < 12) $hour += 12;
                }
                elseif ($period == 'night') {
                    if ($hour == 12) $hour = 0;
                    elseif ($hour < 12) $hour += 12;
                }
            } else {
                // Saat verilmemişse, zaman dilimine göre varsayılan
                switch ($period) {
                    case 'morning': $hour = 9; break;
                    case 'noon': $hour = 12; break;
                    case 'afternoon': $hour = 15; break;
                    case 'evening': $hour = 19; break;
                    case 'night': $hour = 22; break;
                    default: $hour = 9;
                }
            }

            return ['hour' => $hour, 'minute' => $minute, 'period' => $period];
        }

        /* -----------------------------------------------------------
         * APPLY TIME
         * -----------------------------------------------------------*/

        private function applyTimeToDate(DateTime $date, $time)
        {
            $date->setTime($time['hour'], $time['minute'], 0);

            // saat geçmişse yarına at
            if ($date < $this->now) {
                $date->modify('+1 day');
            }

            return $date->format('Y-m-d H:i:s');
        }

        /* -----------------------------------------------------------
         * GENERIC
         * -----------------------------------------------------------*/

        private function parseGeneric($text)
        {
            $date = clone $this->now;

            if (preg_match('/(\d+)\s*(gün|hafta|ay)\s*(sonra|içinde)/u', $text, $m)) {
                $num = (int)$m[1];
                $unit = $m[2];

                if ($unit == 'gün') $date->modify("+$num days");
                if ($unit == 'hafta') $date->modify("+$num weeks");
                if ($unit == 'ay') $date->modify("+$num months");
            }

            $time = $this->extractTime($text);
            return $this->applyTimeToDate($date, $time);
        }

        /* -----------------------------------------------------------
         * VALIDATION
         * -----------------------------------------------------------*/

        public function validateDateTime($dateTimeStr)
        {
            try {
                $date = new DateTime($dateTimeStr);

                if ($date < $this->now) {
                    return [
                        'valid'=>false,
                        'message'=>'Geçmiş tarih',
                        'suggestion'=>$this->now->modify('+1 day')->format('Y-m-d H:i:s')
                    ];
                }

                $hour = (int)$date->format('H');

                if ($hour < 8 || $hour > 20) {
                    return [
                        'valid'=>false,
                        'message'=>'Çalışma saatleri dışında',
                        'suggestion'=>$date->setTime(max(8,min($hour,20)),0,0)->format('Y-m-d H:i:s')
                    ];
                }

                return [
                    'valid'=>true,
                    'datetime'=>$dateTimeStr,
                    'formatted'=>$date->format('d.m.Y H:i')
                ];

            } catch (Exception $e) {
                return [
                    'valid'=>false,
                    'message'=>'Tarih parse edilemedi'
                ];
            }
        }
    }
