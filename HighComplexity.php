<?php

class Reduction 
{
    public function checkDeclinationsForValue($declinations)
    {
        $valid = false;
        $validDeclinations = array();
        $nbNights = 0;
        foreach ($declinations as $declination) {
            if (
                    $declination->getDeclination()->getProduitAttributeValeurFromIdAttribut(\Attribut::KEY_STAY_PERIOD) ||
                    $declination->getDeclination()->getProduitAttributeValeurFromIdAttribut(\Attribut::KEY_VISIT)
            ) {
                $nbNights += $declination->getDeclination()->getNbNightDeclination();
                $validDeclinations[] = $declination;
                if ($nbNights >= ($this->getBoughtNights() + $this->getFreeNights())) {
                    $valid = true;
                    $this->setValidDeclinationsForSale($validDeclinations);
                    break;
                }
            }
        }
        return $valid;
    }

    public function calculateValue()
    {
        $value = 0;
        $totalPrice = 0;
        $decrementedValue = $this->getPercentage();
        foreach ($this->getValidDeclinationsForSale() as $declination) {
            $declinationSale = 0;
            $currentPrice = $this->getCurrentPriceForDeclination($declination);
            /* @var $declination \LogiCE\Entity\Order\Declination */
            $totalPrice += $currentPrice;
            if ($this->getUnit() === self::UNIT_EURO) {
                if (!$decrementedValue <= 0) {
                    if ($currentPrice <= $decrementedValue) {
                        $declinationSale = $currentPrice;
                    } else {
                        $declinationSale = $currentPrice - $decrementedValue;
                    }
                    $decrementedValue -= $declinationSale;
                }
            } else if ($this->getUnit() === self::UNIT_PERCENT) {
                $declinationSale = ($this->getPercentage() / 100) * $currentPrice;
            }
            $this->updateCurrentPriceForDeclination($declination, $declinationSale);
            $this->addSalesToDeclination($declination, $declinationSale);
        }
        if ($this->getUnit() === self::UNIT_EURO) {
            $value = $this->getPercentage() - $decrementedValue;
        } else if ($this->getUnit() === self::UNIT_PERCENT) {
            $value = ($this->getPercentage() / 100) * $totalPrice;
        }
        return $value;
    }
}

class DateHelper
{
    public static function addMonth($date, $nbMonths): ?string
    {
        $arrayDate = explode('-', $date);

        if (count($arrayDate) > 1) {
            $newMonth = $arrayDate[1];
            $newYear = $arrayDate[0];
            for ($i = 0; $i < $nbMonths; $i++) {
                $newMonth += 1;
                if ($newMonth > 12) {
                    $newMonth = 1;
                    $newYear += 1;
                }
            }

            if (strlen($newMonth) < 2) {
                $newMonth = '0' . $newMonth;
            }

            return $newYear . '-' . $newMonth . '-' . $arrayDate[2];
        }

        return null;
    }
}
