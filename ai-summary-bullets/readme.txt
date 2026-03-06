=== AI Summary Bullets ===
Contributors: HP van Hagen
Tags: ai, openai, summary, bullets, seo, editor, content
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Genereer beknopte "In dit artikel:"-samenvattingen met bullets via OpenAI. Inclusief kopieerknop, optioneel kader en lengte-/kostenbewaking.

== Description ==
AI Summary Bullets maakt op de bewerkpagina van berichten een samenvatting: een introzin + korte bullets in HTML. Ideaal om bovenaan je artikel te plaatsen voor betere scanbaarheid en SEO/AI-resultaten.

**Belangrijkste features**
- Knop "Maak samenvatting" in de editor (metabox).
- Kopieerknop voor snelle plaatsing in je content.
- Optioneel **kader** rond de samenvatting + instelbare padding.
- **Lengte-/kostenbewaking**: begrens invoer, toon (geschatte) tokens en kosten.
- **Model-refresh**: haal actuele OpenAI-modellen op.
- **i18n**: vertalingsgereed (text domain: `ai-summary-bullets`).

**Privacy**
De (ingekorte) tekst van je bericht wordt naar de OpenAI API gestuurd voor verwerking. Plaats geen gevoelige persoonsgegevens in je content als je dit niet wilt delen met OpenAI.

== Installation ==
1. Maak een map `ai-summary-bullets` met:
   - `ai-summary-bullets.php`
   - `readme.txt`
2. Zip de map en upload via **Plugins → Nieuwe plugin → Plugin uploaden**.
3. Activeer de plugin.
4. Ga naar **Instellingen → AI Summary** en vul in:
   - OpenAI API key (te verkrijgen via https://platform.openai.com/).
   - Introzin, aantal bullets, taal, model (eventueel **Refresh modellen**).
   - (Optioneel) Kader + padding.
   - (Optioneel) Max. tekens en kostenindicatie (prijzen per 1K tokens).

== Usage ==
1. Open een **Bericht** in de editor.
2. In de metabox **AI Samenvatting** klik **Maak samenvatting**.
3. Controleer resultaat, bekijk metrics (tokens/kosten indien ingeschakeld).
4. Klik **Kopieer** en plak de HTML bovenaan je artikel (visuele of code-weergave).
5. Publiceer of werk bij.

== Frequently Asked Questions ==
= Waar verschijnt de knop? =
In de bewerkpagina van **Berichten** als metabox “AI Samenvatting” (zijbalk).

= Werkt dit ook voor Pagina’s of custom post types? =
Standaard alleen voor **Berichten**. Uitbreiden kan door de `add_meta_box`-aanroep aan te passen in de plugin (CPT’s toevoegen).

= Wat als mijn artikel heel lang is? =
De plugin kapt invoer af op de ingestelde **Max. tekens** (woordgrens) om fouten en kosten te beperken.

= Kan ik de HTML-stijl aanpassen? =
Ja. Je kunt het **kader** aan/uit zetten en padding per zijde instellen. Eventueel kun je na plakken eigen klassen of CSS toevoegen.

= Slaat de plugin iets op in de database? =
Alleen instellingen onder **Instellingen → AI Summary**. Samenvattingen worden niet automatisch opgeslagen bij het bericht.

== Screenshots ==
1. Instellingenpagina met API-key, model, taal, kader en kostenopties.
2. Metabox “AI Samenvatting” met knoppen, status en resultaatveld.

== Changelog ==
= 1.2.2 =
- Ook mogelijk maken op pagina

= 1.2.1 =
- Verwijderd: “Plak in editor”-functie (stabiliteit/compatibiliteit).
- Behouden: kopieerknop, kader, lengte-/kostenbewaking, i18n.

= 1.2.0 =
- Nieuw: lengte-/kostenbewaking met (optionele) tarieven.
- Nieuw: i18n (text domain `ai-summary-bullets`).
- Nieuw (verwijderd in 1.2.1): “Plak in editor”.

= 1.1.1 =
- Kader + padding-instellingen.
- Opruiming outputmodus; eenvoudiger workflow.

= 1.1.0 =
- Kopieerknop, outputinstellingen en inline admin JS.

= 1.0.0 =
- Eerste release: samenvatting genereren met OpenAI op bericht-bewerken pagina.

== Upgrade Notice ==
= 1.2.1 =
Verwijdert de “Plak in editor”-knop voor betrouwbaarheid. Gebruik de kopieerknop en plak handmatig in je artikel.

== License ==
Dit project is gelicentieerd onder de GPLv2 of later.
