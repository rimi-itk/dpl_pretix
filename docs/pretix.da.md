# pretix

## Skabelonevents

Et _skabelonevent_ skal enten være et “Singular event” eller en “Event series” med ét og kun ét sub-event (jf. [Creating
an event](https://docs.pretix.eu/en/latest/user/events/create.html)).

Det anbefales at skabelonevents ikke er offentligt tilgængelige, dvs. er i “Test mode” og ikke er “live”.

### Produkter

#### Priser

I dpl-cms har vi kun én pris pr. event(serie), men i pretix kan vi have flere produkter, fx “Standard”, “Handicapbillet”
og “Hjælperbillet”. “Hjælperbillet” er en tillægsbillet der kun kan bestilles i forbindelse med “Handicapbillet” og bør
derfor være gratis. Vi sætter altid prisen på alle produkter, og det styres i pretix at tillægsbilletten er gratis når
den købes sammen med en anden billet.

### Kvoter

Kvoter skal være opsat til at gælde på tværs af alle produkter.
