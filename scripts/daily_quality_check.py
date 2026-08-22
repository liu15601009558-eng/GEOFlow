#!/usr/bin/env python3
"""
GEOFlow 文章质量每日检查脚本
检查 90817 系列英文文章的语言质量
"""
import subprocess
import re
from datetime import datetime, timedelta

# 配置
DB_CMD = ['docker', 'exec', 'geo-system-postgres', 'psql', '-U', 'geo_user', '-d', 'geo_system']
CHINESE_PATTERN = re.compile(r'[\u4e00-\u9fff]')
ENGLISH_PATTERN = re.compile(r'[a-zA-Z]{4,}')

def run_sql(query):
    result = subprocess.run(DB_CMD + ['-t', '-A', '-c', query], capture_output=True, text=True)
    return result.stdout.strip()

def check_language_quality():
    """检查最近 24 小时生成的 90817 文章"""
    yesterday = (datetime.now() - timedelta(days=1)).strftime('%Y-%m-%d')
    
    # 获取最近 24 小时的文章
    articles = run_sql(f"""
        SELECT id, title, LEFT(content, 2000) 
        FROM articles 
        WHERE task_id IN (13, 19, 20) 
        AND DATE(created_at) >= '{yesterday}'
        ORDER BY created_at DESC;
    """)
    
    if not articles:
        print("No new articles to check today.")
        return
    
    fails = []
    for line in articles.split('\n'):
        if line and '|' in line:
            parts = line.split('|')
            if len(parts) >= 3:
                aid, title, content = parts[0], parts[1], parts[2]
                has_chinese = bool(CHINESE_PATTERN.search(content))
                
                if has_chinese:
                    fails.append((aid, title))
                    # 记录失败
                    detail = f"Chinese characters detected. Title: {title[:50]}".replace("'", "''")
                    run_sql(f"""
                        INSERT INTO article_quality_check (article_id, check_type, check_result, detail)
                        VALUES ({aid}, 'language', 'fail', '{detail}')
                        ON CONFLICT (article_id, check_type) DO UPDATE SET check_result='fail', detail='{detail}', created_at=CURRENT_TIMESTAMP;
                    """)
    
    # 输出报告
    total = len([l for l in articles.split('\n') if l])
    fail_count = len(fails)
    pass_count = total - fail_count
    
    print(f"=== 90817 系列质量检查报告 ===")
    print(f"检查时间: {datetime.now().strftime('%Y-%m-%d %H:%M')}")
    print(f"检查文章数: {total}")
    print(f"通过: {pass_count} | 失败: {fail_count}")
    print(f"通过率: {pass_count/total*100:.1f}%" if total > 0 else "N/A")
    
    if fails:
        print(f"\n⚠️ 失败文章列表:")
        for aid, title in fails:
            print(f"  - ID {aid}: {title[:50]}")
    
    # 如果失败率超过 20%，发送告警
    if total > 0 and fail_count / total > 0.2:
        print(f"\n🔴 告警: 失败率 {fail_count/total*100:.1f}% 超过阈值 20%!")
    
    return fails

if __name__ == '__main__':
    check_language_quality()
