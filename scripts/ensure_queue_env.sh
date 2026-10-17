#!/bin/bash
# ensure_queue_env.sh
# 确保 .env.prod 含 queue worker 所需的非 git 化配置。
#
# 背景：.env.prod 被 .gitignore 忽略（含真实密钥），所以它的改动不会进版本库。
# 换机器、从 .env.prod.example 重建、或 docker 重建后，本脚本可一键恢复。
#
# 幂等：已存在的键不会重复追加。只追加，不覆盖任何现有内容。
#
# 用法: bash scripts/ensure_queue_env.sh [--check] [目标.env文件]
#        bash scripts/ensure_queue_env.sh --check   # 只检查不修改
#        bash scripts/ensure_queue_env.sh --check path/to/.env  # 检查指定文件

set -euo pipefail

# 定位项目根目录（脚本可能在任意 cwd 下执行）
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$ROOT/.env.prod"

CHECK_ONLY=0
args=()
for a in "$@"; do
    case "$a" in
        --check) CHECK_ONLY=1 ;;
        -h|--help)
            sed -n '1,14p' "$0"
            exit 0
            ;;
        *) args+=("$a") ;;
    esac
done
# 第二个位置参数 = 目标文件（默认 .env.prod；可用于测试或处理非默认路径）
if [ "${#args[@]}" -ge 1 ]; then
    ENV_FILE="${args[0]}"
fi

# 期望存在的 键=值 对（按追加顺序）
declare -a WANTED=(
    "COMPOSER_ON_START=false"
)

MISSING=0
CHANGED=0

if [ ! -f "$ENV_FILE" ]; then
    echo "❌ 找不到 $ENV_FILE"
    echo "   先执行: cp .env.prod.example .env.prod 并填入真实密钥"
    exit 1
fi

for kv in "${WANTED[@]}"; do
    key="${kv%%=*}"
    # 已存在（任意值）→ 跳过；存在但值不同 → 提示但不覆盖
    if grep -qE "^${key}=" "$ENV_FILE"; then
        current=$(grep -E "^${key}=" "$ENV_FILE" | head -1 | cut -d= -f2-)
        if [ "$current" = "${kv#*=}" ]; then
            echo "✅ $key 已正确设置"
        else
            echo "⚠️  $key 已存在但值为 '$current'（期望 '${kv#*=}'），未覆盖，请人工确认"
        fi
        continue
    fi
    MISSING=$((MISSING+1))
    if [ "$CHECK_ONLY" -eq 1 ]; then
        echo "❌ $key 缺失"
        continue
    fi

    # 缺失 → 备份后追加
    bak="$ENV_FILE.bak-$(date +%Y%m%d-%H%M%S)"
    cp -p "$ENV_FILE" "$bak"
    printf '\n# 由 scripts/ensure_queue_env.sh 追加于 %s\n# queue worker 常驻；vendor 已存在，跳过每次启动的 composer install。\n# entrypoint 兜底：vendor 缺失时仍会安装（if [ ! -f vendor/autoload.php ]）。\n%s\n' \
        "$(date '+%Y-%m-%d %H:%M:%S')" "$kv" >> "$ENV_FILE"
    echo "✅ 已追加 $key（备份: $(basename "$bak")）"
    CHANGED=$((CHANGED+1))
done

echo ""
if [ "$CHECK_ONLY" -eq 1 ]; then
    echo "检查模式：$MISSING 个键缺失，未做修改"
    [ "$MISSING" -eq 0 ] && exit 0 || exit 2
fi

if [ "$CHANGED" -gt 0 ]; then
    echo "已修改 $CHANGED 个键。queue 容器需重建才生效："
    echo "  docker compose -f docker-compose.prod.restore.yml up -d queue"
    exit 0
fi

echo "无缺失项，无需修改。"
exit 0
