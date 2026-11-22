## FontBakery report

fontbakery version: 1.1.0







## Check results



<details><summary>[3] Family checks</summary>
<div>
<details>
    <summary>🔥 <b>FAIL</b> Verify that family names in the name table are consistent across all fonts in the family. Checks Typographic Family name (nameID 16) if present, otherwise uses Font Family name (nameID 1) <a href="https://fontbakery.readthedocs.io/en/stable/fontbakery/checks/opentype.html#opentype-family-consistent-family-name">opentype/family/consistent_family_name</a></summary>
    <div>







* 🔥 **FAIL** <p>2 different Font Family names were found:</p>
<ul>
<li>
<p>'Prozone Display' was found in:</p>
<ul>
<li>ProzoneDisplay.ttf (nameID 1)</li>
</ul>
</li>
<li>
<p>'Prozone Logo' was found in:</p>
<ul>
<li>ProzoneLogo.ttf (nameID 1)</li>
</ul>
</li>
</ul>
 [code: inconsistent-family-name]



</div>
</details>

<details>
    <summary>🔥 <b>FAIL</b> Ensure VFs have 'ital' STAT axis. <a href="https://fontbakery.readthedocs.io/en/stable/fontbakery/checks/opentype.html#opentype-STAT-ital-axis">opentype/STAT/ital_axis</a></summary>
    <div>







* 🔥 **FAIL** <p>Italic font ProzoneDisplay.ttf has no matching Roman font.</p>
 [code: missing-roman]



* 🔥 **FAIL** <p>Italic font ProzoneLogo.ttf has no matching Roman font.</p>
 [code: missing-roman]



</div>
</details>

<details>
    <summary>🔥 <b>FAIL</b> Typographic Family name consistency. <a href="https://fontbakery.readthedocs.io/en/stable/fontbakery/checks/universal.html#typographic-family-name">typographic_family_name</a></summary>
    <div>







* 🔥 **FAIL** <p>Name ID 16 (Typographic Family name) is not consistent across fonts. Values found: ['Prozone Display', 'Prozone Logo']</p>
 [code: incosistent-family-name]



</div>
</details>
</div>
</details>

<details><summary>[1] ProzoneDisplay.ttf</summary>
<div>
<details>
    <summary>⚠️ <b>WARN</b> Check if each glyph has the recommended amount of contours. <a href="https://fontbakery.readthedocs.io/en/stable/fontbakery/checks/universal.html#contour-count">contour_count</a></summary>
    <div>







* ⚠️ **WARN** <p>This check inspects the glyph outlines and detects the total number of contours in each of them. The expected values are infered from the typical ammounts of contours observed in a large collection of reference font families. The divergences listed below may simply indicate a significantly different design on some of your glyphs. On the other hand, some of these may flag actual bugs in the font such as glyphs mapped to an incorrect codepoint. Please consider reviewing the design and codepoint assignment of these to make sure they are correct.</p>
<p>The following glyphs do not have the recommended number of contours:</p>
<pre><code>- Glyph name: B	Contours detected: 3	Expected: 2

- Glyph name: E	Contours detected: 1	Expected: 2

- Glyph name: G	Contours detected: 1	Expected: 2 or 3

- Glyph name: I	Contours detected: 1	Expected: 2

- Glyph name: J	Contours detected: 1	Expected: 2

- Glyph name: P	Contours detected: 1	Expected: 2
</code></pre>
 [code: contour-count]



</div>
</details>
</div>
</details>

<details><summary>[1] ProzoneLogo.ttf</summary>
<div>
<details>
    <summary>⚠️ <b>WARN</b> Check if each glyph has the recommended amount of contours. <a href="https://fontbakery.readthedocs.io/en/stable/fontbakery/checks/universal.html#contour-count">contour_count</a></summary>
    <div>







* ⚠️ **WARN** <p>This check inspects the glyph outlines and detects the total number of contours in each of them. The expected values are infered from the typical ammounts of contours observed in a large collection of reference font families. The divergences listed below may simply indicate a significantly different design on some of your glyphs. On the other hand, some of these may flag actual bugs in the font such as glyphs mapped to an incorrect codepoint. Please consider reviewing the design and codepoint assignment of these to make sure they are correct.</p>
<p>The following glyphs do not have the recommended number of contours:</p>
<pre><code>- Glyph name: B	Contours detected: 3	Expected: 2

- Glyph name: E	Contours detected: 3	Expected: 2

- Glyph name: G	Contours detected: 1	Expected: 2 or 3

- Glyph name: I	Contours detected: 1	Expected: 2

- Glyph name: J	Contours detected: 1	Expected: 2

- Glyph name: P	Contours detected: 1	Expected: 2

- Glyph name: E	Contours detected: 3	Expected: 1
</code></pre>
 [code: contour-count]



</div>
</details>
</div>
</details>




### Summary

| 💥 ERROR | ☠ FATAL | 🔥 FAIL | ⚠️ WARN | ⏩ SKIP | ℹ️ INFO | ✅ PASS | 🔎 DEBUG | 
| ---|---|---|---|---|---|---|---|
| 0 | 0 | 3 | 2 | 93 | 6 | 136 | 0 | 
| 0% | 0% | 1% | 1% | 39% | 2% | 57% | 0% | 



**Note:** The following loglevels were omitted in this report:


* SKIP
* INFO
* PASS
* DEBUG
