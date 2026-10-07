# Simplified Chinese glossary

This translation is an AI draft, not yet reviewed by a native speaker.

The terms the `zh_CN` translation uses. A new text takes them from here; a
term that changes, changes here and in the `.po` file at once.

## Registers

- `zh_CN` addresses the reader with the polite *您*, *您的*, as Nextcloud's
  own `zh_CN` does in core and settings (“请联系您的管理员。”, “您的账号”);
  *你* does not appear.
- Instructions are plain imperatives, softened with *请* where Nextcloud
  does so: “请先启用它。”
- A button or menu entry names its action as a plain verb phrase, as
  Nextcloud's own do: “添加规则”, “重新计算”, “全部验证”.

## Terms

| English | Chinese | Note |
|---|---|---|
| checksum | 校验和 | the English's word for the value |
| hash | 哈希值 | only where the English keeps *hash*: the hash filter, “在哈希值的任意位置匹配”, the “哈希值” field of the Duplicates filter |
| hashing | 哈希计算 | the process, where the English says *hashing* |
| algorithm | 算法 | “校验和算法” |
| duplicates | 重复文件 | also the page's name, “重复文件” |
| group (of duplicate files) | 文件组 | a set of files sharing a checksum; never 群组 |
| rule | 规则 | |
| personal rule | 个人规则 | an account's rule for its own home folder; not 用户自己的规则 |
| band | 层级 | the evaluation layer a rule falls in: “层级 7”, “层级 7，位置 1”; 7.1 is “层级 7 中的第一条规则” |
| position | 位置 | a rule's place among the rules of the same scope in its band |
| priority | 优先级 | “优先级层级” |
| scope | 适用范围 | Nextcloud's `files_external` term for the same field; “同一适用范围的规则” |
| selector | 选择器 | a scope as the API writes it, `home:alice`; the parameter name `selector` is typed as it is |
| place (a storage, a team folder, all home folders) | 区域 | what has, or lacks, a catch-all rule of its own; not 位置, which is *position* |
| evaluate | 评估 | |
| enforced | 强制 | Nextcloud's term (强制群组, 强制执行); the field and column are “强制” |
| catch-all rule / default | 兜底规则 / 默认规则 | |
| Add rule | 添加规则 | the button, and the text that names it |
| Type | 类型 | the field; its values below |
| Include / Ignore / Exclude | 包含 / 忽略 / 排除 | a rule **type**'s name in the form, named in running text as “包含”; the table shows `"include"` etc. as they are |
| Mode | 模式 | the field; its values below |
| Auto / Missing / Force / Lazy | 自动 / 补缺 / 重建 / 延后 | a rule **mode**'s name in the form; *Force* is 重建 so it cannot be read as 强制 (enforced) |
| home folder | 主目录 | Nextcloud's term (用户主目录) |
| team folder | 团队文件夹 | Nextcloud's term; the selector prefix `groupfolder:` is typed as it is |
| share (a received one) | 共享 | Nextcloud's term (收到的共享); the location prefix `share:` is typed as it is |
| address (a rule's scope as one string) | 地址 | `home:alice//Documents/**` is typed as it is |
| storage | 存储 | |
| raw ID | 内部 ID | |
| external storage, external mount | 外部存储 | Nextcloud's term |
| account | 账号 | Nextcloud's term; “单个账号”, “所选账号” |
| user | 用户 | only in “用户指南” (*User guide*); a person with a login is an account, 账号 |
| group | 群组 | Nextcloud's term |
| member | 成员 | |
| administrator | 管理员 | |
| admin group | 管理员群组 | |
| group admin | 分组管理员 | Nextcloud's word for a sub-admin, as its account management names the role (“Group admin for”: “分组管理员”) |
| this server | 此服务器 | not 此实例; “大多数服务器” |
| app password | 应用密码 | Nextcloud's term |
| filesystem access (of an app password) | 文件系统访问权限 | Nextcloud's token option is “允许访问文件系统”, named so in running text |
| Security (the personal settings section) | 安全 | Nextcloud's own name |
| token | 令牌 | Nextcloud's term (设备令牌) |
| sudo token | Sudo 令牌 | |
| sudoers | Sudoers | |
| grant (noun / verb) | 授权 | a sudo token's standing permission; 已授权 / 未授权 as states, “授权” as the column heading |
| revoke | 撤销 | Nextcloud's term |
| cross-account | 跨账号 | |
| look across accounts | 跨账号查看 | |
| Mine / Others | 我的 / 其他人的 | the tabs; both answer “谁的文件” and keep the possessive 的 |
| recalculate | 重新计算 | the sidebar section and its button, “重新计算” |
| verify | 验证 | Nextcloud's term; “验证”, “全部验证” |
| reapply | 重新应用 | |
| indexed | 已索引 | |
| pending (deletion) | 等待……删除 | “等待后台任务删除” |
| queued files | 队列中的文件 | |
| untrusted / eroded / reset | 不可信 / 已丢弃 / 已重置 | the states of stale checksums. *Eroded* checksums are already deleted (已丢弃). *Reset* is a state, not the action: checksums a reset has made invalid (已重置), still stored until the background job deletes them |
| invalidated (by a reset) | 作废 | “因重置而作废”; not 弃用, which is *deprecated* |
| background job | 后台任务 | Nextcloud's term |
| rule sweep / rule reapplication / queue drain / orphan purge / checksum copy / checksum index check / checksum stamp check | 规则扫描 / 规则重新应用 / 队列处理 / 孤立条目清理 / 复制已有校验和 / 校验和索引检查 / 校验和时间戳检查 | the background jobs' names |
| import | 导入 | the `occ fcias:import` command only; the *checksum copy* job copies (复制), it does not import |
| current (value) | 当前 | “当前：{hash}”; not 现在, which is adverbial |
| Acknowledge (a button) | 知道了 | the idle banner's dismiss button; the toast that follows it says 已知悉 |
| tunables | 可调参数 | |
| prefill | 预填充 | |
| picker | 选择器 | |
| glob pattern | Glob 表达式 | not 模式, which is *mode* |
| path | 路径 | |
| sidebar | 侧边栏 | Nextcloud's term |
| file sidebar | 文件侧边栏 | Nextcloud's term |
| Unified Search | 统一搜索 | Nextcloud's term |
| tab | 选项卡 | |
| cover (a rule covers files) | 涵盖 | not 覆盖 |

## Style

- The app's name, *File Checksum Index & Search*, stays English.
- Chinese sentences take full-width punctuation: ，。：；？！（）、. A label
  that pairs a word with a value uses the full-width colon with no space:
  “群组：{group}”, “总计：{count}”.
- A name in running text takes Chinese quotation marks with no space around
  them, and reads exactly as that element's own label: “所有主目录”, “安全”,
  “添加规则”, a type as “包含”. A value shown or typed as it is keeps
  straight quotes and stays English: `"**"`, `"/"`, `"include"`, `"hash"`,
  `local::/path/`; so does a placeholder standing for one: `"%s" 类型的规则`.
- Latin words, numbers and `{placeholders}` are set off from Chinese
  characters by a space (“使用 API”, “1 到 500”, “下载 {file}”), but not from
  full-width punctuation (“（{error}）”, “：%s”), as Nextcloud's `zh_CN` does.
- A failure names what failed: “无法保存规则。” One with nothing to name
  reads “保存失败。”, as the English's “Saving failed.”.
- A dash between clauses is usually rewritten with a comma, colon or
  brackets; where a dash stays, it is the Chinese dash ——, without spaces:
  “强制——所有主目录”.
- An ellipsis follows the word directly, with no space: “正在保存…”,
  “选择群组…”. Written as the character itself, never three dots.
- `{placeholders}`, `%s` and `%n` stay as they are; a counted noun takes its
  measure word: “%n 个文件”.
