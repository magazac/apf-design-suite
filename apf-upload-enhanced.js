// Enhanced upload system for APF Design Suite
(function(){
    class APFUploadEnhanced {
        constructor() {
            this.isUploading = false;
            this.uploadQueue = [];
            this.init();
        }

        init() {
            this.bindEvents();
        }

        bindEvents() {
            const self = this;
            
            // File input change event
            document.addEventListener('change', function(e) {
                if (e.target.id === 'apfdu-file' || e.target.classList.contains('apf-enhanced-upload')) {
                    self.handleFileUpload(e.target.files[0]);
                }
            });

            // Form submit event - YENİ EKLENEN KOD
            document.addEventListener('submit', function(e) {
                const form = e.target;
                if (form.classList.contains('cart') && self.isUploading) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    
                    self.showMessage('Lütfen dosya yüklenmesini bekleyin...', 'error');
                    return false;
                }
            });

            // WooCommerce AJAX sepete ekleme için
            document.body.addEventListener('click', function(e) {
                if (e.target.classList.contains('single_add_to_cart_button') && self.isUploading) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    
                    self.showMessage('Lütfen dosya yüklenmesini bekleyin...', 'error');
                    return false;
                }
            });
        }

        async handleFileUpload(file) {
            if (!file) return;

            console.log('Dosya seçildi:', file.name);

            // Yükleme başladığını işaretle
            this.isUploading = true;
            this.disableAddToCartButton(true);
            this.showMessage('Dosya yükleniyor...', 'info');

            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            if (!allowedTypes.includes(file.type)) {
                this.showMessage('Sadece JPG, JPEG, PNG, GIF ve WebP dosyaları yükleyebilirsiniz.', 'error');
                this.isUploading = false;
                this.disableAddToCartButton(false);
                return;
            }

            // Validate file size (max 4MB)
            if (file.size > 4 * 1024 * 1024) {
                this.showMessage('Dosya boyutu 4MB\'dan küçük olmalıdır.', 'error');
                this.isUploading = false;
                this.disableAddToCartButton(false);
                return;
            }

            const formData = new FormData();
            formData.append('action', 'apf_upload_design');
            formData.append('design_file', file);
            formData.append('nonce', this.getNonce());

            try {
                console.log('AJAX isteği gönderiliyor...');
                
                // AJAX URL'sini güvenli şekilde al
                const ajaxUrl = this.getAjaxUrl();
                console.log('AJAX URL:', ajaxUrl);
                
                const response = await fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData
                });

                console.log('AJAX yanıtı alındı:', response);

                const result = await response.json();
                console.log('JSON sonucu:', result);

                if (result.success) {
                    this.onUploadSuccess(result.data);
                } else {
                    throw new Error(result.data);
                }
            } catch (error) {
                console.error('Upload error:', error);
                this.showMessage(`Yükleme hatası: ${error.message}`, 'error');
            } finally {
                // Yükleme bittiğinde butonu aktif et
                this.isUploading = false;
                this.disableAddToCartButton(false);
            }
        }

        disableAddToCartButton(disable) {
            const addToCartBtn = document.querySelector('.single_add_to_cart_button');
            if (addToCartBtn) {
                if (disable) {
                    addToCartBtn.disabled = true;
                    addToCartBtn.style.opacity = '0.6';
                    addToCartBtn.textContent = 'Dosya Yükleniyor...';
                } else {
                    addToCartBtn.disabled = false;
                    addToCartBtn.style.opacity = '1';
                    addToCartBtn.textContent = addToCartBtn.getAttribute('data-original-text') || 'Sepete Ekle';
                    
                    // Orijinal text'i sakla (ilk sefer için)
                    if (!addToCartBtn.getAttribute('data-original-text')) {
                        addToCartBtn.setAttribute('data-original-text', addToCartBtn.textContent);
                    }
                }
            }
        }

        getAjaxUrl() {
            // Önce APF_UPLOAD_DATA'dan dene, sonra global ajaxurl'den, sonra default
            if (window.APF_UPLOAD_DATA && window.APF_UPLOAD_DATA.ajaxurl) {
                return window.APF_UPLOAD_DATA.ajaxurl;
            } else if (window.ajaxurl) {
                return window.ajaxurl;
            } else {
                // Varsayılan WordPress AJAX URL'si
                return '/wp-admin/admin-ajax.php';
            }
        }

        getNonce() {
            return (window.APF_UPLOAD_DATA && window.APF_UPLOAD_DATA.nonce) || '';
        }

        onUploadSuccess(data) {
            console.log('Yükleme başarılı:', data);
            
            // Store upload data in hidden fields
            this.createHiddenField('apf_uploaded_design_id', data.attachment_id);
            this.createHiddenField('apf_uploaded_design_url', data.url);
            this.createHiddenField('apf_uploaded_design_title', data.title);

            // Update preview
            this.updatePreview(data.url);
            
            // Show success message
            this.showMessage('Tasarım başarıyla yüklendi!', 'success');
        }

        createHiddenField(name, value) {
            let field = document.querySelector(`input[name="${name}"]`);
            if (!field) {
                field = document.createElement('input');
                field.type = 'hidden';
                field.name = name;
                const form = document.querySelector('form.cart');
                if (form) {
                    form.appendChild(field);
                }
            }
            field.value = value;
            console.log('Hidden field güncellendi:', name, value);
        }

        updatePreview(imageUrl) {
            console.log('Önizleme güncelleniyor:', imageUrl);
            
            // Update artboard preview
            const artboard = document.querySelector('.apf-artboard');
            if (artboard) {
                let designImg = artboard.querySelector('.apf-design');
                if (!designImg) {
                    designImg = document.createElement('img');
                    designImg.className = 'apf-design';
                    artboard.appendChild(designImg);
                }
                designImg.src = imageUrl;
                designImg.style.display = 'block';
            }

            // Create thumbnail preview near upload button
            this.createThumbnailPreview(imageUrl);
        }

        createThumbnailPreview(imageUrl) {
            let preview = document.querySelector('.apf-upload-preview');
            if (!preview) {
                preview = document.createElement('div');
                preview.className = 'apf-upload-preview';
                const uploadSection = document.querySelector('.apf-upload-ui');
                if (uploadSection) {
                    uploadSection.appendChild(preview);
                }
            }

            preview.innerHTML = `
                <div style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                    <strong>Yüklenen Tasarım:</strong>
                    <img src="${imageUrl}" style="max-width: 100px; height: auto; display: block; margin-top: 5px; border-radius: 4px;" />
                    <small style="display: block; margin-top: 5px; color: #666;">Bu görsel sepette ve siparişinizde gösterilecektir.</small>
                </div>
            `;
        }

        showMessage(message, type = 'info') {
            // Mevcut mesajları temizle
            const existingMessages = document.querySelectorAll('.apf-upload-status');
            existingMessages.forEach(msg => msg.remove());

            const messageDiv = document.createElement('div');
            messageDiv.className = `apf-upload-status ${type}`;
            messageDiv.style.cssText = `
                padding: 12px; margin: 10px 0; border-radius: 6px; 
                background: ${type === 'success' ? '#d4edda' : 
                            type === 'error' ? '#f8d7da' : 
                            type === 'info' ? '#d1ecf1' : '#fff3cd'};
                color: ${type === 'success' ? '#155724' : 
                        type === 'error' ? '#721c24' : 
                        type === 'info' ? '#0c5460' : '#856404'};
                border: 1px solid ${type === 'success' ? '#c3e6cb' : 
                                  type === 'error' ? '#f5c6cb' : 
                                  type === 'info' ? '#bee5eb' : '#ffeaa7'};
                font-weight: 500;
            `;
            messageDiv.textContent = message;
            
            const uploader = document.querySelector('.apf-uploader');
            if (uploader) {
                uploader.appendChild(messageDiv);
            }
            
            // Başarı ve info mesajlarını 5 saniye sonra kaldır, hata mesajlarını tut
            if (type === 'success' || type === 'info') {
                setTimeout(() => {
                    if (messageDiv.parentNode) {
                        messageDiv.remove();
                    }
                }, 5000);
            }
        }
    }

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
        console.log('APF Upload Enhanced yükleniyor...');
        console.log('APF_UPLOAD_DATA:', window.APF_UPLOAD_DATA);
        console.log('ajaxurl:', window.ajaxurl);
        new APFUploadEnhanced();
    });
})();