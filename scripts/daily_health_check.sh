#!/bin/bash
# daily_health_check.sh
# 每日检查所有网站健康状态

LOG_FILE="/home/liupeng/GEOFlow/logs/health_check_$(date +%Y%m%d).log"
mkdir -p /home/liupeng/GEOFlow/logs

echo "=== GEOFlow 健康检查 $(date) ===" | tee -a $LOG_FILE

# 1. 检查重复文章
echo "" | tee -a $LOG_FILE
echo "【1】重复文章检查" | tee -a $LOG_FILE

# 程恩医疗器械
CHENGEN_DUP=$(ssh -i ~/.ssh/211.149.151.138_id_ed25519 -p 22000 root@211.149.151.138 "mysql -u chengenyiliao_cn -p'ScQGH7QXYX' chengenyiliao_cn -N -e \"
SELECT COUNT(*) FROM (
    SELECT title, COUNT(*) as cnt 
    FROM ey_archives 
    WHERE is_del = 0 AND title != ''
    GROUP BY title 
    HAVING cnt > 1
) as t;
\"" 2>/dev/null)

if [ "$CHENGEN_DUP" -gt 0 ]; then
    echo "❌ 程恩医疗器械: $CHENGEN_DUP 个重复标题" | tee -a $LOG_FILE
else
    echo "✅ 程恩医疗器械: 无重复" | tee -a $LOG_FILE
fi

# 铁塔厂
TIETACHANG_DUP=$(ssh -i ~/.ssh/211.149.151.138_id_ed25519 -p 22000 root@211.149.151.138 "mysql -u tietachang_com -p'WJczF8BtizAsbbTX' tietachang_com -N -e \"
SELECT COUNT(*) FROM (
    SELECT title, COUNT(*) as cnt 
    FROM ey_archives 
    WHERE is_del = 0 AND title != ''
    GROUP BY title 
    HAVING cnt > 1
) as t;
\"" 2>/dev/null)

if [ "$TIETACHANG_DUP" -gt 0 ]; then
    echo "❌ 铁塔厂: $TIETACHANG_DUP 个重复标题" | tee -a $LOG_FILE
else
    echo "✅ 铁塔厂: 无重复" | tee -a $LOG_FILE
fi

# 2. 检查图片URL
echo "" | tee -a $LOG_FILE
echo "【2】图片URL检查" | tee -a $LOG_FILE

CHENGEN_BAD_IMG=$(ssh -i ~/.ssh/211.149.151.138_id_ed25519 -p 22000 root@211.149.151.138 "mysql -u chengenyiliao_cn -p'ScQGH7QXYX' chengenyiliao_cn -N -e \"
SELECT COUNT(*) FROM ey_article_content 
WHERE content LIKE '%img.chengenyiliao.cn%';
\"" 2>/dev/null)

if [ "$CHENGEN_BAD_IMG" -gt 0 ]; then
    echo "❌ 程恩医疗器械: $CHENGEN_BAD_IMG 篇文章使用错误图片URL" | tee -a $LOG_FILE
else
    echo "✅ 程恩医疗器械: 图片URL正常" | tee -a $LOG_FILE
fi

# 3. 检查SEO描述
echo "" | tee -a $LOG_FILE
echo "【3】SEO描述检查" | tee -a $LOG_FILE

CHENGEN_BAD_DESC=$(ssh -i ~/.ssh/211.149.151.138_id_ed25519 -p 22000 root@211.149.151.138 "mysql -u chengenyiliao_cn -p'ScQGH7QXYX' chengenyiliao_cn -N -e \"
SELECT COUNT(*) FROM ey_archives 
WHERE is_del = 0 
AND (seo_description LIKE '%! image%' 
   OR seo_description LIKE '%copy.jpg%'
   OR seo_description LIKE '%.JPG%');
\"" 2>/dev/null)

if [ "$CHENGEN_BAD_DESC" -gt 0 ]; then
    echo "❌ 程恩医疗器械: $CHENGEN_BAD_DESC 篇文章有异常SEO描述" | tee -a $LOG_FILE
else
    echo "✅ 程恩医疗器械: SEO描述正常" | tee -a $LOG_FILE
fi

# 4. 检查同步接口
echo "" | tee -a $LOG_FILE
echo "【4】同步接口检查" | tee -a $LOG_FILE

CHENGEN_API=$(curl -sL -o /dev/null -w "%{http_code}" "https://www.chengenyiliao.cn/sync_article.php?action=status" -H "X-Sync-Token: geoflow_sync_2026_ceyl" 2>/dev/null)
TIETACHANG_API=$(curl -sL -o /dev/null -w "%{http_code}" "https://www.tietachang.com/sync_article.php?action=status" -H "X-Sync-Token: geoflow_sync_2026_ceyl" 2>/dev/null)
TOWER_API=$(curl -sL -o /dev/null -w "%{http_code}" "https://90817.com/sync_article.php?action=status" -H "X-Sync-Token: geoflow_sync_2026_90817_monopole" 2>/dev/null)

if [ "$CHENGEN_API" = "200" ]; then
    echo "✅ 程恩医疗器械: API正常" | tee -a $LOG_FILE
else
    echo "❌ 程恩医疗器械: API异常 (HTTP $CHENGEN_API)" | tee -a $LOG_FILE
fi

if [ "$TIETACHANG_API" = "200" ]; then
    echo "✅ 铁塔厂: API正常" | tee -a $LOG_FILE
else
    echo "❌ 铁塔厂: API异常 (HTTP $TIETACHANG_API)" | tee -a $LOG_FILE
fi

if [ "$TOWER_API" = "200" ]; then
    echo "✅ 90817: API正常" | tee -a $LOG_FILE
else
    echo "❌ 90817: API异常 (HTTP $TOWER_API)" | tee -a $LOG_FILE
fi

# 5. 检查文章数量变化
echo "" | tee -a $LOG_FILE
echo "【5】文章数量统计" | tee -a $LOG_FILE

CHENGEN_COUNT=$(ssh -i ~/.ssh/211.149.151.138_id_ed25519 -p 22000 root@211.149.151.138 "mysql -u chengenyiliao_cn -p'ScQGH7QXYX' chengenyiliao_cn -N -e \"SELECT COUNT(*) FROM ey_archives WHERE is_del = 0;\"" 2>/dev/null)
TIETACHANG_COUNT=$(ssh -i ~/.ssh/211.149.151.138_id_ed25519 -p 22000 root@211.149.151.138 "mysql -u tietachang_com -p'WJczF8BtizAsbbTX' tietachang_com -N -e \"SELECT COUNT(*) FROM ey_archives WHERE is_del = 0;\"" 2>/dev/null)

echo "程恩医疗器械: $CHENGEN_COUNT 篇" | tee -a $LOG_FILE
echo "铁塔厂: $TIETACHANG_COUNT 篇" | tee -a $LOG_FILE

echo "" | tee -a $LOG_FILE
echo "=== 检查完成 ===" | tee -a $LOG_FILE
