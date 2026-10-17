# Hermes gateway 故障修复方案（根因已确认）

> **状态**：根因已定位并验证。不是网络问题，不是升级问题，是**环境变量注入失败**。
> 升级（v0.21.6）本身已完成，但 gateway 至今还在跑 9/21 的旧进程。

---

## 一、根因（已验证，非推测）

```
配置 ~/.hermes/config.yaml:4-5
  base_url: https://token.sensenova.cn/v1
  api_key:  ${HERMES_CUSTOM_TOKEN_SENSENOVA_CN_API_KEY}   ← key 来自环境变量

gateway 进程 (PID 4076138) environ 里 SENSENOVA 变量数 = 0   ← 拿不到 key
我的 shell 里该变量                                     = 35 字符 (sk-lQH...oquu)
```

**链条**：
1. Hermes 把 API key 放在环境变量，配置里用 `${VAR}` 引用
2. gateway 由 systemd --user (PID 1227) 拉起，该 manager **8/22 启动，环境冻结 50 天**
3. gateway 进程里没有任何 SENSENOVA 变量 → 每次请求带空 key
4. 服务端返回 `{"code":16,"message":"Forbidden"}` → Hermes 包装成 `APIConnectionError`
5. 重试 5 次（每次间隔指数增长，总耗时 600-710 秒）→ 全部失败

**这同时解释了**：为什么我的 CLI 会话完全正常（继承当前 shell 环境），
而 gateway 的所有 cron job 全挂。两条启动路径环境不同，这是判断失误的根源。

---

## 二、影响范围（10-02 至 10-12，10 天以上）

| 指标 | 数值 |
|------|------|
| 失败 API 调用 | 14,771+（journal 起点 10/02，**无法确定更早是否存在**）|
| 耗尽全部 5 次重试 | 5,364 |
| `geoflow-auto-sync` 失败 | 663 |
| `weixin-session-monitor` 失败 | 658 |
| MCP 初始化失败 | 767 |
| 每次请求耗时 | 624-710 秒（超时型失败）|

**盲区说明**：journal 最早只到 10/02 07:21，8-9 月查询返回 0 是因为**没有数据**，
不是没有故障。故障起始时间无法确定。

---

## 三、修复方案

### 步骤 1：把 key 固化进 systemd 环境（关键，一次性解决）

在真实终端执行（写入 liupeng 自己的配置，不需要 sudo）：

```bash
mkdir -p ~/.config/environment.d
umask 077
cat > ~/.config/environment.d/10-hermes.env <<'EOF'
HERMES_CUSTOM_TOKEN_SENSENOVA_CN_API_KEY=sk-lQH...oquu
EOF
chmod 600 ~/.config/environment.d/10-hermes.env
```

> **必须先把 key 换成你的真实值**。上面是占位前缀。
> 如果你不确定完整值，执行 `echo "$HERMES_CUSTOM_TOKEN_SENSENOVA_CN_API_KEY"`
> 在**当前这个能拿到 key 的 shell** 里取。

### 步骤 2：重建 systemd user 环境（必须，否则步骤 1 不生效）

systemd 只在 manager 启动时读一次 environment.d，**必须重建 DBUS session**：

```bash
loginctl enable-linger liupeng          # 确保登出后 user manager 仍在
loginctl terminate-user liupeng         # 杀掉冻结的 manager (PID 1227)
# 重新登录后 manager 自动重启，并读取 10-hermes.env
```

⚠️ `terminate-user` 会杀掉 gateway 和**当前会话**。执行前请确保能重新登录。
如果你不希望这么做，替代方案是步骤 2b。

### 步骤 2b（替代，侵入更小）：直接在 unit 里注入

```bash
cp ~/.config/systemd/user/hermes-gateway.service{,.bak-20261012}
python3 - <<'PY'
import os, re
p = os.path.expanduser('~/.config/systemd/user/hermes-gateway.service')
key = os.environ.get('HERMES_CUSTOM_TOKEN_SENSENOVA_CN_API_KEY')
assert key, '当前 shell 没有该变量'
s = open(p).read()
if 'Environment=' not in s:
    s = s.replace('[Service]', '[Service]\nEnvironment=HERMES_CUSTOM_TOKEN_SENSENOVA_CN_API_KEY=%s\n' % key)
    open(p, 'w').write(s)
print('已注入')
PY
```

这个方案的 key 会明文写进 unit 文件，注意权限：
```bash
chmod 600 ~/.config/systemd/user/hermes-gateway.service
```

### 步骤 3：重启 gateway 并验证

```bash
nohup bash /tmp/restart_gw.sh >/dev/null 2>&1 &
```

验收（5 条必须全中）：
```bash
cat /tmp/gw_restart_result.txt
# 1. 新 PID ≠ 4076138
# 2. lstart 是今天，不是 9/21
# 3. ActiveState=active
# 4. hermes --version = v0.21.6
# 5. 重启后 30 秒内 'API call failed' 新增数 = 0
```

第 5 条是真正的验收标准 —— 前三条只证明进程换了，第 5 条才证明故障修好。

---

## 四、回滚

```bash
# 撤销 unit 改动
mv ~/.config/systemd/user/hermes-gateway.service.bak-20261012 \
   ~/.config/systemd/user/hermes-gateway.service

# 撤销环境变量文件
rm ~/.config/environment.d/10-hermes.env

# 回滚代码版本
git -C ~/.hermes/hermes-agent checkout 1fe8683e5
```

备份位置：`~/.config/systemd/user/hermes-gateway.service.bak-20261012`、
`~/.hermes/installs/6ac7d72649addc5a/source-completion-pending.bak-20261011`、
`/tmp/package*.bak-20261011`

---

## 五、我的错误记录（查证过程中推翻的判断）

| # | 我当时的判断 | 实际情况 |
|---|---|---|
| 1 | DBUS session 缺失导致 `gateway status` 超时 | DBUS 有值，socket 存在 |
| 2 | journal 阻塞导致超时 | `journalctl` 2.3s、`show` 0.035s 均正常 |
| 3 | cgroup 子进程过多导致超时 | `pgrep -P` 为空，`Tasks: 24` |
| 4 | "成功调用 = 0" 证明 gateway 从未成功 | **错**。日志级别只有 WARNING/ERROR，成功请求不写日志 |
| 5 | API 故障今天 09:28 才开始 | 实际每天约 2,700 次，至少持续到 10/02 |
| 6 | 网络问题/代理黑洞 | curl 直连和走代理均 0.28s 返回 |

真因是**环境变量注入失败**，前 6 条全是红鲱鱼。

---

## 六、仍未验证

1. **故障起始时间** — journal 只保留 10 天，无法判断 9 月是否也在失败
2. **`hermes gateway status` 慢的真因** — 对比测试显示同环境同命令
   `openclaw-gateway` 4.6s、`gost-proxy` 7.6s、`hermes-gateway` 12.96s，
   但底层原因未定位（已排除 DBUS/journal/cgroup/网络）
3. **`~/.hermes/node` 里 npm 升级的影响面** — 该 npm 也可能被其他项目调用
4. **663 次 `geoflow-auto-sync` 失败是否真的丢了文章** — 需查 GEOFlow
   PostgreSQL 的发布记录对比
