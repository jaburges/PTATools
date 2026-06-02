# Child name backfill — Case C mismatches (2026-06-02)

**Total mismatches**: 79
**Listed below**: 79 (capped at 200 in script, real total = 79)

These are orders where the parent has registered children in azure_user_children,
but the legacy meta value (Childs Name / Child Name / Student Name / etc.) does not
exactly match any of them. The backfill did NOT auto-link these. Review each row.

For each, decide: (a) add sibling (new child row), (b) link to existing child id,
or (c) ignore. Run a separate one-off SQL or use the +Child UI once that ships.

Patterns observed:
- **Sibling**: order has a different first name, existing children have different names → add as new sibling
- **Truncation**: order has "Edward", existing has "Edward Rat" → same kid, just write _azure_pf_child_id
- **Ambiguous**: order has just a surname like "Kirsch" with multiple matching kids → manual judgment

---

| Order | Parent | Email | Meta key | Order value | Existing children | Product |
|-------|--------|-------|----------|-------------|-------------------|---------|
| 31952 | 864 | nwpmcinc@gmail.com | Student Name | Ayden Sellers | #191 Sophia Duggan, #194 Sophie Duggan | Spirit Wear: Youth T-Shirt |
| 31910 | 771 | sorina.rat@gmail.com | Child Name | Edward | #61 Edward Rat | 2025-26 Wilder Yearbook |
| 31904 | 834 | rabbianoor4@gmail.com | Child Name | Muhammad Ashaz Bajwa | #151 Muhammad Ashaz, #189 Shanzay Bajwa | 2025-26 Wilder Yearbook |
| 31862 | 938 | amanda.s.jaeger@gmail.com | Child Name | Nora | #245 Elin | 2025-26 Wilder Yearbook |
| 31854 | 1259 | dubtyler@msn.com | Child Name | Maxwell Maybee | #247 Demsey Maybee | 2025-26 Wilder Yearbook |
| 31825 | 1174 | brettrafuse@hotmail.com | Child Name | Harrison Rafuse | #248 Spencer Rafuse | 2025-26 Wilder Yearbook |
| 31653 | 852 | ravikumar.lrk@gmail.com | Child Name | Saisri Rithika Lingamallu | #175 Rithika, #176 Rithika Lingamallu | 2025-26 Wilder Yearbook |
| 31558 | 813 | katie@kirsch.org | Child Name | Kirsch | #118 Katie Kirsch, #121 Kaylee Kirsch | 2025-26 Wilder Yearbook |
| 31552 | 550 | vinaya_kamat@yahoo.com | Child Name | Dighe | #185 Sara Dighe | 2025-26 Wilder Yearbook |
| 31506 | 1256 | akimball@lwsd.org | Child Name | Family 3 | #250 Lexi Kimball | 2025-26 Wilder Yearbook |
| 31505 | 1256 | akimball@lwsd.org | Child Name | Family 4 | #250 Lexi Kimball | 2025-26 Wilder Yearbook |
| 31504 | 1256 | akimball@lwsd.org | Child Name | Family 5 | #250 Lexi Kimball | 2025-26 Wilder Yearbook |
| 31503 | 1256 | akimball@lwsd.org | Child Name | Family 6 | #250 Lexi Kimball | 2025-26 Wilder Yearbook |
| 31502 | 1256 | akimball@lwsd.org | Child Name | Family 7 | #250 Lexi Kimball | 2025-26 Wilder Yearbook |
| 31501 | 1256 | akimball@lwsd.org | Child Name | Family 8 | #250 Lexi Kimball | 2025-26 Wilder Yearbook |
| 31500 | 1256 | akimball@lwsd.org | Child Name | Family 9 | #250 Lexi Kimball | 2025-26 Wilder Yearbook |
| 31499 | 1256 | akimball@lwsd.org | Child Name | Family 10 | #250 Lexi Kimball | 2025-26 Wilder Yearbook |
| 31489 | 810 | gruppgirl@hotmail.com | Child Name | Caulfield | #111 Joshua Caulfield, #141 Matthew Caulfield | 2025-26 Wilder Yearbook |
| 31489 | 810 | gruppgirl@hotmail.com | Child Name | Caulfield | #111 Joshua Caulfield, #141 Matthew Caulfield | 2025-26 Wilder Yearbook |
| 31475 | 698 | erinclarson@gmail.com | Child Name | Larson | #53 Damon Larson, #73 Erin Larson, #123 Kendall Larson | 2025-26 Wilder Yearbook |
| 31475 | 698 | erinclarson@gmail.com | Child Name | Larson | #53 Damon Larson, #73 Erin Larson, #123 Kendall Larson | 2025-26 Wilder Yearbook |
| 31389 | 740 | kathrawstron@outlook.com | Child Name | Archie and Jack Rawstron | #18 Archie Rawstron, #101 Jack Rawstron | 2025-26 Wilder Yearbook |
| 31337 | 757 | nicoleachapman@gmail.com | Childs Name | Cora Wilson | #42 Callum Wilson, #49 Coraline Wilson | Rainy Day Dinner Club [2-5] (Spring 2026) |
| 29292 | 440 | mjossi@lwsd.org | Childs Name | Canyon Jossi | #255 Luna Jossi | Rainy Day Dinner Club [2-5] (Spring 2026) |
| 26484 | 838 | pracs28@gmail.com | Childs Name | Niranjana P Hingway | #158 Niranjana Hingway | Chess [K-5] (Spring 2026) |
| 26484 | 838 | pracs28@gmail.com | Childs Name | Niranjana P Hingway | #158 Niranjana Hingway | Hand Sewing [K-5] (Spring 2026) |
| 26474 | 835 | deepa.shivnani@gmail.com | Childs Name | Myra L | #152 Myra Ladkani | Ceramics - Tuesdays [3-5] (Spring 2026) |
| 26435 | 736 | michaelia729@gmail.com | Childs Name | Alivia | #14 Alivia Lu | Chess [K-5] (Spring 2026) |
| 26416 | 866 | k.sivan08@gmail.com | Childs Name | Yani | #193 Sophie, #195 Sophie shamir, #226 Yani Shamir | Musical Theater [K-5] (Spring 2026) |
| 26416 | 866 | k.sivan08@gmail.com | Childs Name | Yani | #193 Sophie, #195 Sophie shamir, #226 Yani Shamir | Chess [K-5] (Spring 2026) |
| 26416 | 866 | k.sivan08@gmail.com | Childs Name | Yani | #193 Sophie, #195 Sophie shamir, #226 Yani Shamir | Hand Sewing [K-5] (Spring 2026) |
| 26369 | 864 | nwpmcinc@gmail.com | Child Name | Sophia Dugga | #191 Sophia Duggan, #194 Sophie Duggan | 2025-26 Wilder Yearbook |
| 26292 | 757 | nicoleachapman@gmail.com | Student Name | Cora Wilson | #42 Callum Wilson, #49 Coraline Wilson | Spirit Wear: Youth Hoodie |
| 26292 | 757 | nicoleachapman@gmail.com | Student Name | Cora Wilson | #42 Callum Wilson, #49 Coraline Wilson | Spirit Wear: Youth Hoodie |
| 26281 | 342 | jenn.jacobs@live.com | Child Name | Jacobs | #19 Aria Jacobs | 2025-26 Wilder Yearbook |
| 26254 | 869 | petedaniell@gmail.com | Student Name | Theo Daniell | #205 Theodore Daniell | Spirit Wear: Adult Hoodie |
| 26132 | 859 | pallavi.v.inamdar@gmail.com | Child Name | Samved Jakatadar | #183 Samved Jakatdar | 2025-26 Wilder Yearbook |
| 26059 | 1126 | aliciajones@windermere.com | Child Name | Emery Jones | #260 Nolan Jones | 2025-26 Wilder Yearbook |
| 26059 | 1126 | aliciajones@windermere.com | Student Name | Emery Jones | #260 Nolan Jones | Spirit Wear: Youth Hoodie |
| 26053 | 817 | becky@grandmontstudio.com | Student Name | Lana | #125 Lana Grandmont | Spirit Wear: Adult Hoodie |
| 26053 | 817 | becky@grandmontstudio.com | Student Name | Lana | #125 Lana Grandmont | Spirit Wear: Adult Crewneck Sweatshirt |
| 26044 | 740 | kathrawstron@outlook.com | Student Name | Archie and Jack Rawstron | #18 Archie Rawstron, #101 Jack Rawstron | Spirit Wear: Adult Hoodie |
| 26044 | 740 | kathrawstron@outlook.com | Student Name | Archie and Jack Rawstron | #18 Archie Rawstron, #101 Jack Rawstron | Spirit Wear: Youth T-Shirt |
| 26027 | 757 | nicoleachapman@gmail.com | Child Name | Cora Wilson | #42 Callum Wilson, #49 Coraline Wilson | 2025-26 Wilder Yearbook |
| 25735 | 837 | iyers.archana@gmail.com | Childs Name | Nikhil | #157 Nikhil Thakre | Ceramics - Mondays [3-5] (Winter 2026) |
| 25734 | 1240 | alishab@duparandcompany.com | Childs Name | Lesley Brown | #256 Lesley Beown | Ceramics - Tuesdays [3-5] (Winter 2026) |
| 25732 | 756 | sdschaab@hotmail.com | Childs Name | Cadence | #41 Cadence Schaab | Ceramics - Mondays [3-5] (Winter 2026) |
| 25651 | 814 | wrightkyla15@gmail.com | Childs Name | Kayden | #120 Kayden Montgomery | Hands on Science [3-5] (Winter 2026) |
| 25633 | 1243 | pcfringe@gmail.com | Childs Name | Niranjana P Hingway | #251 Niranjana Hingway | CreART [K-5] (Winter 2026) |
| 25632 | 1243 | pcfringe@gmail.com | Childs Name | Niranjana P Hingway | #251 Niranjana Hingway | Chess [K-5] (Winter 2026) |
| 25606 | 748 | brette.mcwilliams@gmail.com | Childs Name | Ben | #28 Beckett McWilliams, #29 Ben McWilliams | Rainy Day Dinner Club [3-5] (Winter 2026) |
| 25590 | 719 | ciciqb@gmail.com | Childs Name | Prescilla | #129 Lincoln Kao, #170 Prescilla Kao | Rainy Day Dinner Club [3-5] (Winter 2026) |
| 25565 | 1135 | bethany.maloney@gmail.com | Childs Name | Ireland | #252 Ireland Maloney | Ceramics - Mondays [3-5] (Winter 2026) |
| 24619 | 1240 | alishab@duparandcompany.com | Childs Name | Lesley Brown | #256 Lesley Beown | Algepros [5] |
| 24615 | 818 | dreamqueen16@gmail.com | Childs Name | Lillian Perry | #128 Lily Modrell | Ceramics [3-5] |
| 24610 | 316 | GUSIA_STAR@YAHOO.COM | Childs Name | Ezra Mason | #100 Ivana Samoilova, #197 Stefan, #198 Stefan Samoilov | Hands on Science [3-5] |
| 24607 | 713 | sanyananda107@gmail.com | Childs Name | Kabir | #115 Kabir Bhatia, #210 Tulip bhatia | Soccer [K-2] |
| 24604 | 1250 | nadeem.bajwa@gmail.com | Childs Name | Shanzay Bajwa | #265 Muhammad Ashaz | Ceramics [3-5] |
| 24603 | 835 | deepa.shivnani@gmail.com | Childs Name | Myra L | #152 Myra Ladkani | Hands on Science [3-5] |
| 24569 | 1235 | thebestdaniellestpierre@gmail.com | Childs Name | Nafiseh Shawish | #261 Majed and Nafiseh Shawish | Hands on Science [3-5] |
| 24555 | 426 | mbauma1@gmail.com | Childs Name | Beulah | #31 Beulah Webb | Ceramics [3-5] |
| 24542 | 835 | deepa.shivnani@gmail.com | Childs Name | Myra | #152 Myra Ladkani | Algepros [5] |
| 24541 | 712 | okadry16@gmail.com | Childs Name | Lucia | #131 Lucia Villamizar | Algepros [5] |
| 24523 | 1235 | thebestdaniellestpierre@gmail.com | Childs Name | Majed Shawish | #261 Majed and Nafiseh Shawish | Hands on Science [3-5] |
| 24517 | 657 | scottl@wilderptsa.net | Child&#039;s Name | Sebastian Livengood-Rizo | #259 PTSA | Celebration Book |
| 24498 | 739 | claraz8@yahoo.com | Child&#039;s Name | Anne Doleac | #17 Annie Doleac | Celebration Book |
| 24497 | 655 | nicoleachapman@gmail.com | Childs Name | Coraline Wilson | #272 Callum Wilson | Theater Cast |
| 24483 | 1243 | pcfringe@gmail.com | Childs Name | Niranjana P Hingway | #251 Niranjana Hingway | Soccer [K-2] |
| 24479 | 698 | erinclarson@gmail.com | Childs Name | Damon | #53 Damon Larson, #73 Erin Larson, #123 Kendall Larson | CreArt [K-5] |
| 24472 | 715 | shannon_kiesling@yahoo.com | Childs Name | Josephine Antonsen | #253 Josie Antonsen | Art Club [5] |
| 24448 | 1243 | pcfringe@gmail.com | Childs Name | Niranjana P Hingway | #251 Niranjana Hingway | Junior Musical Theater [K-2] |
| 24434 | 713 | sanyananda107@gmail.com | Childs Name | Tulip | #115 Kabir Bhatia, #210 Tulip bhatia | Art Club [5] |
| 24427 | 1235 | thebestdaniellestpierre@gmail.com | Childs Name | Nafiseh Shawish | #261 Majed and Nafiseh Shawish | Theater Cast |
| 24417 | 1227 | omfowler@gmail.com | Childs Name | Mateo Botello Fowler | #275 Isabella Botello Fowler | Soccer [K-2] |
| 24411 | 715 | shannon_kiesling@yahoo.com | Childs Name | Josephine Antonsen | #253 Josie Antonsen | Algepros [5] |
| 24374 | 880 | kboyd@outlook.com | Childs Name | William | #222 William Chester | Soccer [K-2] |
| 24365 | 725 | shadi_ri@yahoo.com | Childs Name | Ella | #5 Aiden Mehr, #64 Ella Mehr | Junior Musical Theater [K-2] |
| 24359 | 1240 | alishab@duparandcompany.com | Childs Name | Lesley Brown | #256 Lesley Beown | Theater Cast |
| 24343 | 620 | mika.y.timmons@gmail.com | Childs Name | Sully | #199 Sully Timmons | Soccer [K-2] |
