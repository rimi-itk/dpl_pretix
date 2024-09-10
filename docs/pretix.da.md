# pretix

## Skabelonevents

Et _skabelonevent_ skal enten være et “Singular event” eller en “Event series” med ét og kun ét sub-event (jf. [Creating
an event](https://docs.pretix.eu/en/latest/user/events/create.html)).

Det anbefales at skabelonevents ikke er offentligt tilgængelige, dvs. er i “Test mode” og ikke er “live”.

### Produkter

#### Priser

I dpl-cms har vi kun én pris pr. event(serie), men i pretix kan vi have flere produkter, fx “Standard”, “Handicapbillet”
og “Hjælperbillet”. “Hjælperbillet” er en tillægsbillet der kun kan bestilles i forbindelse med “Handicapbillet” og er
derfor gratis, dvs. har en pris på 0. Når vi opdaterer priser i pretix (fra dpl-cms) sætter vi kun priser på produkter
der i forvejen har en pris der _ikke er 0_, og derved undgår vi at sætte en pris på tillægsbilletter. Det kræver
tilsvarende at almindelige billetter på betalingsarrangementer skal have en pris der ikke er 0.

### Kvoter

Kvoter skal være opsat til at gælde på tværs af alle produkter.
