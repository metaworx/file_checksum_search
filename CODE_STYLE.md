# CODE_STYLE.md

**Project Code Style Configuration**  
*Exported from `.idea/codeStyles/Project.xml` (PhpStorm 2025.3+ / IntelliJ-based IDEs)*

## Table of Contents

- [1. General (Scheme Level)](#1-general-scheme-level)
- [2. CSS](#2-css)
- [3. HTML / H2 (HTML2)](#3-html--h2-html2)
- [4. JavaScript](#4-javascript)
- [5. JSON](#5-json)
- [6. XML](#6-xml)
- [7. PHP – Core Settings (PHPCodeStyleSettings)](#7-php--core-settings-phpcodestylesettings)
- [8. PHP – Tabs and Indents](#8-php--tabs-and-indents)
- [9. PHP – Spaces](#9-php--spaces)
- [10. PHP – Wrapping and Braces](#10-php--wrapping-and-braces)
- [11. PHP – Blank Lines](#11-php--blank-lines)
- [12. PHP – Arrangement (Custom Ordering)](#12-php--arrangement-custom-ordering)
- [13. Comparison: PHP Code Beautifier and Fixer vs Laravel Pint](#13-comparison-php-code-beautifier-and-fixer-vs-laravel-pint)

This document explains every setting in plain English, grouped exactly like the **Settings | Editor | Code Style**dialog
in PhpStorm.  
It also translates the rules (where possible) to:

- **EditorConfig** (`.editorconfig`)
- **PHP Code Beautifier and Fixer** (PHPCBF / PHP_CodeSniffer)
- **Laravel Pint** (PHP-CS-Fixer wrapper)

---

## 1. General (Scheme Level)

### 1.1 Automatic Indent Detection

**Description:** PhpStorm will **never** auto-detect indentation from existing files. All indents follow the explicit
rules below.

**PhpStorm**

```xml

<option name="AUTODETECT_INDENTS"
        value="false"/>
```

**EditorConfig**  
`N/A` (EditorConfig is always explicit)

**PHP Code Beautifier and Fixer**  
`N/A`

**Laravel Pint**  
`N/A`

### 1.2 Line Separator

**Description:** All files use Unix-style line endings (`\n` / LF).

**PhpStorm**

```xml
<!-- In file: <option name="LINE_SEPARATOR" value="&#10;" /> -->
<option name="LINE_SEPARATOR"
        value="&amp;#10;"/>
```

**EditorConfig**

```ini
end_of_line = lf
```

**PHP Code Beautifier and Fixer**  
`N/A` (tool respects the file)

**Laravel Pint**  
`N/A`

---

## 2. CSS

### 2.2 Hex Colors

**Description:** Hex colors must be lowercase and in 6-digit long format (`#ffffff`). Quotes are enforced where
applicable (e.g. `url()`).

**PhpStorm**

```xml

< CssCodeStyleSettings>
    <option name="HEX_COLOR_LOWER_CASE"
            value="true"/>
    <option name="HEX_COLOR_LONG_FORMAT"
            value="true"/>
    <option name="ENFORCE_QUOTES_ON_FORMAT"
            value="true"/>
</CssCodeStyleSettings>
```

**EditorConfig / PHP tools**  
`N/A` (CSS-only)

---

## 3. HTML / H2 (HTML2)

### 3.1 Use General Style

**Description:** Do **not** fall back to the general code style for HTML files — use CSS/HTML-specific rules.

**PhpStorm**

```xml

<H2CodeStyleSettings version="7">
    <option name="USE_GENERAL_STYLE"
            value="false"/>
</H2CodeStyleSettings>
```

**Other tools**  
`N/A`

---

## 4. JavaScript

### 4.1 Spaces Inside Structures

**Description:** Add spaces inside arrays, objects, imports, and interpolation expressions (e.g. `${ foo }`).

**PhpStorm**

```xml

<JSCodeStyleSettings version="0">
    <option name="SPACE_WITHIN_ARRAY_INITIALIZER_BRACKETS"
            value="true"/>
    <option name="SPACES_WITHIN_OBJECT_LITERAL_BRACES"
            value="true"/>
    <option name="SPACES_WITHIN_IMPORTS"
            value="true"/>
    <option name="SPACES_WITHIN_INTERPOLATION_EXPRESSIONS"
            value="true"/>
</JSCodeStyleSettings>
```

**EditorConfig**  
`N/A` (no JS-specific spacing)

**PHP tools**  
`N/A`

### 4.2 Indentation (JS)

**Description:** Use tabs for indentation.

**PhpStorm**

```xml

<indentOptions>
    <option name="USE_TAB_CHARACTER"
            value="true"/>
</indentOptions>
```

**EditorConfig**

```ini
indent_style = tab
```

**PHP tools**  
`N/A`

---

## 5. JSON

### 5.1 Wrapping & Indent

**Description:** Never wrap on typing. Use 4-space indent.

**PhpStorm**

```xml

<codeStyleSettings language="JSON">
    <option name="WRAP_ON_TYPING"
            value="0"/>
    <indentOptions>
        <option name="INDENT_SIZE"
                value="4"/>
    </indentOptions>
</codeStyleSettings>
```

**EditorConfig**

```ini
indent_style = space
indent_size = 4
```

**PHP tools**  
`N/A`

---

## 6. XML

### 6.1 Attribute Wrapping

**Description:** Wrap XML attributes (value 2 = wrap when long).

**PhpStorm**

```xml

<XML>
    <option name="XML_ATTRIBUTE_WRAP"
            value="2"/>
</XML>
```

**Other tools**  
`N/A`

---

## 7. PHP – Core Settings (PHPCodeStyleSettings)

### 7.1 PHPDoc Formatting

**Description:**

- Align `@param` names and comments
- Blank line before tags and around parameters
- Wrap long PHPDoc lines
- Use 2 spaces between tag/type/name/description
- Tag order weights: `@throws` (4), `@return` (3), `@param` (2), `@since` (1), `@see` (0)
- Use fully-qualified class names (FQCN)

**PhpStorm** (excerpt)

```xml

<option name="ALIGN_PHPDOC_PARAM_NAMES"
        value="true"/>
<option name="PHPDOC_BLANK_LINE_BEFORE_TAGS"
        value="true"/>
<option name="PHPDOC_WRAP_LONG_LINES"
        value="true"/>
<option name="PHPDOC_USE_FQCN"
        value="true"/>
        <!-- weight options -->
```

**EditorConfig**  
`N/A`

**PHP Code Beautifier and Fixer (PHPCBF)**  
No direct rule. Use custom sniff or `Squiz.Commenting` + manual alignment.

**Laravel Pint (PHP-CS-Fixer)**  
Partial: `phpdoc_align`, `phpdoc_order`, `phpdoc_to_comment`, `phpdoc_separation`.  
Add to `pint.json`:

```json
{
    "phpdoc_align": true,
    "phpdoc_order": true
}
```

### 7.2 Constants & Keywords

**Description:** `true`/`false`/`null` must be lowercase. Force short array syntax `[]`.

**PhpStorm**

```xml

<option name="LOWER_CASE_BOOLEAN_CONST"
        value="true"/>
<option name="LOWER_CASE_NULL_CONST"
        value="true"/>
<option name="FORCE_SHORT_DECLARATION_ARRAY_STYLE"
        value="true"/>
```

**EditorConfig**  
`N/A`

**PHP Code Beautifier and Fixer**  
`Generic.PHP.LowerCaseConstant` + `Generic.Arrays.DisallowLongArraySyntax`

**Laravel Pint**

```json
{
    "constant_case": {
        "case": "lower"
    },
    "array_syntax": {
        "syntax": "short"
    }
}
```

### 7.3 Fields & Visibility

**Description:** Default field visibility = `protected`. Variables use camelCase.

**PhpStorm**

```xml

<option name="FIELDS_DEFAULT_VISIBILITY"
        value="protected"/>
<option name="VARIABLE_NAMING_STYLE"
        value="CAMEL_CASE"/>
```

**EditorConfig**  
`N/A`

**PHP Code Beautifier and Fixer**  
`PSR12.Classes.ClassInstantiation` (partial)

**Laravel Pint**  
No direct rule (visibility is style-guide only).

### 7.4 Else-if Style

**Description:** Combine `else if` into `elseif`.

**PhpStorm**

```xml

<option name="ELSE_IF_STYLE"
        value="COMBINE"/>
```

**Laravel Pint**

```json
{
    "elseif": true
}
```

---

## 8. PHP – Tabs and Indents

**Description:** Smart tabs enabled (mix tabs/spaces for alignment). (Indent size defaults to 4.)

**PhpStorm**

```xml

<indentOptions>
    <option name="SMART_TABS"
            value="true"/>
</indentOptions>
```

**EditorConfig**

```ini
indent_style = tab          # or space if you prefer
indent_size = 4
```

**PHP Code Beautifier and Fixer**  
`PSR12.Indent` or `Generic.WhiteSpace.DisallowTabIndent`

**Laravel Pint**

```json
{
    "indentation_type": "tab"
}   # or "space"
```

---

## 9. PHP – Spaces

**Description:** Aggressive spacing: inside parentheses, method calls, if/while/for/switch/catch, array braces, unary
operators, type casts, etc.

**PhpStorm** (key options)

```xml

<option name="SPACE_WITHIN_PARENTHESES"
        value="true"/>
<option name="SPACE_WITHIN_METHOD_CALL_PARENTHESES"
        value="true"/>
<option name="SPACE_AROUND_UNARY_OPERATOR"
        value="true"/>
<option name="SPACE_WITHIN_ARRAY_INITIALIZER_BRACES"
        value="true"/>
        <!-- many more -->
```

**EditorConfig**  
`N/A`

**PHP Code Beautifier and Fixer**  
Many sniffs (`Squiz.WhiteSpace`, `PSR12.ControlStructure`, `Generic.WhiteSpace`)

**Laravel Pint**

```json
{
    "binary_operator_spaces": true,
    "blank_line_after_opening_tag": true,
    "no_extra_blank_lines": true,
    "spaces_inside_parentheses": true,
    "unary_operator_spaces": true
}
```

---

## 10. PHP – Wrapping and Braces

**Description:**

- Braces on new line for control structures (Allman-style, value 2/3)
- Wrapping for calls, parameters, ternary, arrays, assignments
- Force braces on `if`/`while`/`for`/`do-while`
- Chained calls and ternary signs on new line

**PhpStorm** (key)

```xml

<option name="BRACE_STYLE"
        value="2"/>
<option name="IF_BRACE_FORCE"
        value="3"/>
<option name="CALL_PARAMETERS_WRAP"
        value="5"/>
<option name="METHOD_CALL_CHAIN_WRAP"
        value="2"/>
```

**EditorConfig**  
`N/A`

**PHP Code Beautifier and Fixer**  
`PSR12.ControlStructure` + `Squiz.ControlStructures`

**Laravel Pint**

```json
{
    "braces_position": {
        "control_structures": "next_line_unless"
    },
    "method_chaining_indentation": true,
    "ternary_to_null_coalescing": true
}
```

---

## 11. PHP – Blank Lines

**Description:** Strict blank-line rules (e.g. 2 lines after class, 1 before return, 2 around methods).

**PhpStorm** (excerpt)

```xml

<option name="BLANK_LINES_AROUND_CLASS"
        value="2"/>
<option name="BLANK_LINES_AROUND_METHOD"
        value="2"/>
<option name="BLANK_LINES_BEFORE_RETURN_STATEMENT"
        value="1"/>
```

**Laravel Pint**

```json
{
    "blank_line_after_namespace": true,
    "blank_line_before_statement": true,
    "no_extra_blank_lines": {
        "tokens": [
            "curly_brace_block",
            "return"
        ]
    }
}
```

---

## 12. PHP – Arrangement (Custom Ordering)

**Plain English order inside a PHP class/file:**

1. Constants (`// constants` section) – sorted by name
2. Public properties (`// public properties`)
3. Protected properties
4. Private properties
5. Constructor
6. Non-static `_construct*` methods
7. Non-static `config*` / `init*` / `execute*` / `run` methods
8. Non-static magic methods (`__*`) – sorted by name
9. Getters / setters / `is*` / `has*` – sorted by name (kept relative order)
10. All other non-static methods – sorted by name
11. Static methods – sorted by name
12. Traits
13. Interfaces / Classes (file level)

**PhpStorm**  
The entire `<arrangement>` block with custom tokens and sections.

**EditorConfig**  
**No support**

**PHP Code Beautifier and Fixer**  
Limited: `PSR12` has basic ordering. No name-pattern sections.

**Laravel Pint**  
Partial via `ordered_class_elements`:

```json
{
    "ordered_class_elements": {
        "order": [
            "constant",
            "property",
            "constructor",
            "method"
        ],
        "sort_algorithm": "alpha"
    }
}
```

**Limitation:** Cannot replicate custom name patterns (`config*`, magic methods, getters first) or section comments.

---

## 13. Comparison: PHP Code Beautifier and Fixer vs Laravel Pint

| Aspect              | PHP Code Beautifier and Fixer (PHPCBF)              | Laravel Pint                                    |
|---------------------|-----------------------------------------------------|-------------------------------------------------|
| Base technology     | PHP_CodeSniffer (sniffs + fixer)                    | PHP-CS-Fixer                                    |
| Configuration       | `phpcs.xml` (very flexible, custom sniffs possible) | `pint.json` (simple, preset-based)              |
| Speed               | Slower on large projects                            | Very fast                                       |
| Laravel integration | Good (you can include PSR12)                        | Excellent (official `laravel` preset)           |
| Custom arrangement  | Limited (only basic `OrderedClassElementsSniff`)    | Limited (`ordered_class_elements` rule)         |
| Best for            | Enterprise teams needing strict custom rules        | Laravel projects wanting zero-config simplicity |
| CI friendliness     | Mature (many GitHub actions)                        | Excellent (built-in Laravel CI support)         |

**Recommendation for this style**

- Use **Laravel Pint** with the `laravel` preset + the overrides above — it covers ~85% of the settings with almost no
  config.
- Use **PHPCBF** only if you need deeper custom sniffs.
- Neither tool can fully replicate the **Arrangement** section — keep that as a PhpStorm-only rule or enforce manually
  in PRs.

---

**How to apply**

1. Save this file as `CODE_STYLE.md` in your project root.
2. Create `.editorconfig` with the snippets above.
3. For Pint: add the JSON rules to `pint.json` and run `vendor/bin/pint`.
4. For PHPCBF: create `phpcs.xml` with `<rule ref="PSR12"/>` and the additional sniffs.

This style is now fully documented and portable.

```