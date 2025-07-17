jQuery(document).ready(function($) {
    
    // 处理转存按钮点击
    $(document).on('click', '.wp-disk-link-button.transfer-button', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var postId = $button.data('post-id');
        var linkIndex = $button.data('link-index');
        var originalUrl = $button.data('original-url');
        var diskType = $button.data('disk-type');
        
        // 禁用按钮并显示加载状态
        $button.prop('disabled', true);
        $button.data('original-text', $button.text());
        $button.text(wpDiskLinkManager.loading_text);
        $button.addClass('loading');
        
        // 发送转存请求
        $.ajax({
            url: wpDiskLinkManager.ajax_url,
            type: 'POST',
            data: {
                action: 'transfer_disk_link',
                nonce: wpDiskLinkManager.nonce,
                post_id: postId,
                link_index: linkIndex,
                original_url: originalUrl,
                disk_type: diskType
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.url) {
                        // 直接返回了链接（从缓存获取）
                        showTransferSuccess($button, response.data.url, response.data.expire_time);
                    } else if (response.data.transfer_id) {
                        // 开始轮询转存状态
                        pollTransferStatus($button, response.data.transfer_id);
                    }
                } else {
                    showTransferError($button, response.data || wpDiskLinkManager.error_text);
                }
            },
            error: function() {
                showTransferError($button, wpDiskLinkManager.error_text);
            }
        });
    });
    
    // 轮询转存状态
    function pollTransferStatus($button, transferId) {
        var maxRetries = 60; // 最多轮询60次（5分钟）
        var retryCount = 0;
        
        var pollInterval = setInterval(function() {
            retryCount++;
            
            if (retryCount > maxRetries) {
                clearInterval(pollInterval);
                showTransferError($button, '转存超时，请稍后重试');
                return;
            }
            
            $.ajax({
                url: wpDiskLinkManager.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_transfer_status',
                    nonce: wpDiskLinkManager.nonce,
                    transfer_id: transferId
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.status === 'completed') {
                            clearInterval(pollInterval);
                            showTransferSuccess($button, response.data.url, response.data.expire_time);
                        } else if (response.data.status === 'pending') {
                            // 继续轮询
                            $button.text(response.data.message || wpDiskLinkManager.loading_text);
                        }
                    } else {
                        clearInterval(pollInterval);
                        showTransferError($button, response.data || wpDiskLinkManager.error_text);
                    }
                },
                error: function() {
                    // 网络错误时继续重试
                    console.log('轮询状态网络错误，继续重试...');
                }
            });
        }, 5000); // 每5秒轮询一次
    }
    
    // 显示转存成功
    function showTransferSuccess($button, url, expireTime) {
        $button.removeClass('transfer-button loading');
        $button.addClass('transfer-success');
        $button.prop('disabled', false);
        
        // 创建链接元素替换按钮
        var $link = $('<a>', {
            href: url,
            target: '_blank',
            class: $button.attr('class').replace('transfer-button', 'transfer-link'),
            text: $button.data('original-text') || $button.text()
        });
        
        $button.replaceWith($link);
        
        // 显示过期时间提示
        if (expireTime) {
            var expireDate = new Date(expireTime);
            var expireText = '链接有效期至：' + expireDate.toLocaleString();
            $link.attr('title', expireText);
            
            // 在链接后添加过期时间提示
            $('<span>', {
                class: 'expire-notice',
                text: ' (' + expireText + ')',
                style: 'font-size: 0.8em; color: #666; margin-left: 5px;'
            }).insertAfter($link);
        }
    }
    
    // 显示转存错误
    function showTransferError($button, message) {
        $button.removeClass('loading');
        $button.addClass('transfer-error');
        $button.prop('disabled', false);
        $button.text($button.data('original-text') || '重试');
        
        // 显示错误消息
        var $error = $('<span>', {
            class: 'transfer-error-message',
            text: ' - ' + message,
            style: 'color: #dc3232; font-size: 0.9em; margin-left: 5px;'
        });
        
        $error.insertAfter($button);
        
        // 3秒后移除错误消息
        setTimeout(function() {
            $error.remove();
            $button.removeClass('transfer-error');
        }, 3000);
    }
    
    // 复制链接功能
    $(document).on('click', '.copy-link-btn', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var url = $btn.data('url');
        
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function() {
                showCopySuccess($btn);
            }).catch(function() {
                fallbackCopyText(url, $btn);
            });
        } else {
            fallbackCopyText(url, $btn);
        }
    });
    
    // 备用复制方法
    function fallbackCopyText(text, $btn) {
        var textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.top = "0";
        textArea.style.left = "0";
        textArea.style.position = "fixed";
        
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            var successful = document.execCommand('copy');
            if (successful) {
                showCopySuccess($btn);
            } else {
                showCopyError($btn);
            }
        } catch (err) {
            showCopyError($btn);
        }
        
        document.body.removeChild(textArea);
    }
    
    // 显示复制成功
    function showCopySuccess($btn) {
        var originalText = $btn.text();
        $btn.text('已复制');
        $btn.addClass('copy-success');
        
        setTimeout(function() {
            $btn.text(originalText);
            $btn.removeClass('copy-success');
        }, 2000);
    }
    
    // 显示复制错误
    function showCopyError($btn) {
        var originalText = $btn.text();
        $btn.text('复制失败');
        $btn.addClass('copy-error');
        
        setTimeout(function() {
            $btn.text(originalText);
            $btn.removeClass('copy-error');
        }, 2000);
    }
});