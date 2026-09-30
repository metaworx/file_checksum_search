# Japanese glossary

This translation is an AI draft, not yet reviewed by a native speaker.

The terms the Japanese translation `ja` uses. A new text takes them from
here; a term that changes, changes here and in the `.po` file at once.

## Registers

- Sentences addressed to the reader are polite *desu/masu* form, as
  Nextcloud's `ja` writes its settings and core texts:
  「管理者にお問い合わせください。」, 「…をマウントできます。」,
  「保存しました」.
- Buttons, labels, column headings, checkbox labels and tooltips that name
  an action are nouns or the plain dictionary form, as Nextcloud has them:
  「有効にする」, 「取り消す」, 「詳細サイドバーを開く」,
  「ユーザーに外部ストレージの接続を許可する」. A checkbox or switch label
  names the setting it turns on, not a fact:
  「このルールの編集と無効化を管理者のみに許可する」.
- When the app answers the reader, it speaks politely: the toast after
  the 「了解」 button says 「承知しました。…」, not the button's words.
- The reader is *あなた* where a text must name them, which Nextcloud's `ja`
  also does; otherwise the subject is left out, as Japanese prefers.

## Terms

| English | Japanese | Note |
|---|---|---|
| checksum | チェックサム | the English's word for the value |
| hash | ハッシュ | only where the English keeps *hash*: the Hash column, the hash filter, 「ハッシュ内で部分一致」 |
| hashing (the act) | ハッシュ計算 | 「自動ハッシュ計算」 |
| algorithm | アルゴリズム | 「チェックサムアルゴリズム」 |
| duplicates | 重複ファイル | also the page's name, 「重複ファイル」 |
| group (of duplicate files) | 重複グループ | kept apart from グループ, the account group |
| rule | ルール | Nextcloud: 「新規ルールを追加」 |
| personal rule | 個人ルール | an account's rule for its own home folder; not ユーザー自身のルール |
| band | 階層 | the evaluation layer; 「第{band}階層」, 「第{band}階層、位置{position}」; not 範囲, which is the scope |
| position | 位置 | a rule's place among the rules of the same scope in its band |
| priority | 優先度 | band and position joined by a dot: 7.1 is the first rule of 第7階層 |
| scope | 適用範囲 | also the field's name, 「適用範囲」 |
| selector | セレクター | a scope as the API writes it, `home:alice`; the parameter name `selector` is typed as it is |
| place (a storage, a team folder, all home folders) | 場所 | what has, or lacks, a catch-all rule of its own; not used for anything else, so a rule's standing in the order is 「評価順のどこにあるか」 |
| specific (band, rules) | 個別 | only the band's name: 「強制：個別」, 「個別ルール」; *more specific* is より限定的 (「より限定的なルール」) |
| enforced | 強制 | as Nextcloud's 「二要素認証を強制する」 |
| catch-all rule / default | 包括ルール / デフォルト | the catch-all default is 包括的なデフォルト |
| Add rule | ルールを追加 | the button, and the texts that name it |
| Include / Ignore / Exclude | 含める / 無視する / 除外する | the rule's **type** (Type field, タイプ); the table shows `"include"`, `"ignore"`, `"exclude"` as they are |
| Auto / Missing / Force / Lazy | 自動 / 欠落分 / 全件 / 遅延 | the rule's **mode** (Mode field, モード); *Force* is not 強制, which is *enforced*; the table shows `"auto"` … `"lazy"` as they are |
| home folder | ホームフォルダー | |
| team folder | チームフォルダー | the groupfolders app's own `ja` term; the selector prefix `groupfolder:` is typed as it is |
| storage | ストレージ | |
| raw ID | 内部ID | |
| external storage | 外部ストレージ | Nextcloud's term |
| account | アカウント | Nextcloud: 「アカウント」 |
| who (in 「…できるユーザー」) | ユーザー | only where the English says *who*: the 「…できるユーザー」 headings, 「…アクセスできるユーザーを決めます」; never for an account |
| this server | このサーバー | not このインスタンス; 「ほとんどのサーバー」 |
| administrator | 管理者 | |
| admin group | 管理者グループ | |
| group admin | グループ管理者 | Nextcloud's group admin (sub-admin) |
| app password | アプリパスワード | |
| filesystem access | ファイルシステムへのアクセス（権） | the option is Nextcloud's own 「ファイルシステムへのアクセスを許可」 |
| Security (the personal settings section) | セキュリティ | Nextcloud's own name |
| sudo token | sudoトークン | |
| grant (noun / verb) | 承認 / 承認する | not 権限; the switch's states are 「承認済み（{time}）」 / 「未承認」 |
| permission | 権限 | |
| revoke | 取り消す | Nextcloud: 「取り消す」 |
| cross-account | アカウント横断 | *look across accounts*: アカウントを横断して閲覧 |
| sudoers | sudoers | |
| Mine / Others | 自分のファイル / 他のアカウント | the tabs |
| recalculate | 再計算 | 「チェックサムを再計算」 |
| verify | 検証 | Nextcloud: 「検証」 |
| reapply | 再適用 | |
| indexed | インデックス済み | the verb is インデックス化 |
| pending | 保留中 | |
| queued files | キュー内のファイル | |
| untrusted / eroded / reset | 信頼できない / 失効 / リセット済み | the states of stale checksums; *Reset* is the state of checksums a reset has made invalid, a past participle, never the action リセットする |
| disable | 無効にする (noun 無効化) | 「無効にする」, 「このルールの編集と無効化…」 |
| invalidated (by a reset) | 無効化 | 「リセットにより無効化」; not 孤立, the orphan purge's word |
| background job | バックグラウンドジョブ | Nextcloud's own term |
| rule sweep / queue drain / orphan purge / checksum copy / checksum index check | ルールの走査 / キューの処理 / 孤立エントリーの削除 / 既存チェックサムの取り込み / チェックサムインデックスの検査 | |
| tunables | 調整項目 | |
| picker | 選択リスト | |
| prefill | 事前表示 | |
| glob pattern | globパターン | |
| path | パス | |
| sidebar | サイドバー | |
| file sidebar | ファイルサイドバー | Nextcloud's own term |
| tab | タブ | |
| Advanced (settings tab) | 詳細設定 | Nextcloud: 「詳細設定」 |
| Match anywhere in the hash | ハッシュ内で部分一致 | the switch beside the hash filter; 部分一致 is substring matching, 前方一致 (the default) prefix matching; a switch is turned on, 「…をオンにしてください」, never ticked |
| Min. files | 最小ファイル数 | the smallest group to list, a number of files |
| Per page | 表示件数 | how many groups one page shows |
| team folder in a scope | {folders}：{folder} | like 「グループ：{group}」, 「ストレージ：{storage}」; the picker is 「フォルダーを選択…」 |
| folder missing | フォルダー利用不可 | the badge on a rule whose team folder is not available |

## Style

- The app's name, *File Checksum Index & Search*, stays English.
- Punctuation is full-width: 。、：！？（）. A range takes ～ (U+FF5E), as
  Nextcloud's `ja` writes it: 「1～500」; not 〜 (U+301C).
- A name of a UI element in running text takes 「」 and the exact Japanese
  of that element's own label: 「すべてのホームフォルダー」, 「セキュリティ」,
  「ルールを追加」, a type as 「含める」. A value shown or typed as it is keeps
  straight quotes and stays English: `"**"`, `"include"`, `"hash"`,
  `local::/path/`.
- A failure names what failed: 「ルールを保存できませんでした。」. One with
  nothing to name reads 「保存に失敗しました。」, as the English's "Saving
  failed.".
- The English dash between clauses becomes a sentence break, a colon
  (「強制：個別」) or parentheses; Japanese has no spaced dash.
- An ellipsis follows the word directly, with no space: 「グループを検索…」.
  The English's no-break space before it is not carried over.
- `{placeholders}`, `%s` and `%n` stay as they are. One that stands for a
  name, a path or a value takes a half-width space next to kana or kanji
  (「{path} のルールを編集」), as does a code literal (`storages テーブル`,
  `"hash" パラメーター`); one that counts is written tight like a numeral
  (「第{band}階層」, 「%n個のファイル」). A quoted value inside a sentence is
  tight: `"include"は`. Acronyms and product words are tight: API、ID、PHP、
  「occコマンド」, 「sudoersの権限」.
- Katakana long vowels are written out, as Nextcloud's `ja` does in
  フォルダー、ユーザー、サーバー; for consistency also パラメーター,
  ブラウザー and エントリー, where Nextcloud is uneven.
