import React, { useState, useEffect } from 'react';
import { Card, Badge, Button } from '../components/UI';
import { LoadingSpinner } from '../components/Loading';
import { MediaPicker } from '../components/MediaPicker';
import { GoogleFlowEmbed } from '../components/GoogleFlowEmbed';
import { useApi } from '../context/ApiContext';
import { useNotification } from '../context/NotificationContext';

/**
 * Gallery Page
 * Upload and manage media assets for posts + Google Flow integration
 */
export function Gallery() {
  const { fetchApi } = useApi();
  const { notify } = useNotification();
  
  const [tab, setTab] = useState('gallery'); // gallery or google-flow
  const [media, setMedia] = useState([]);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [products, setProducts] = useState([]);
  const [selectedMedia, setSelectedMedia] = useState(null);
  const [perPage] = useState(24);

  useEffect(() => {
    if (tab === 'gallery') {
      loadMedia();
      loadProducts();
    }
  }, [tab, page]);

  const loadMedia = async () => {
    try {
      setLoading(true);
      const res = await fetchApi(`/media?page=${page}&per_page=${perPage}`);
      setMedia(res.media || []);
      setTotal(res.total || 0);
    } catch (error) {
      notify('Failed to load media', 'error');
    } finally {
      setLoading(false);
    }
  };

  const loadProducts = async () => {
    try {
      const res = await fetchApi('/catalog?per_page=1000');
      setProducts(res.products || []);
    } catch (error) {
      console.error('Failed to load products', error);
    }
  };

  const handleUpload = async (files) => {
    try {
      setUploading(true);
      
      for (const file of files) {
        const formData = new FormData();
        formData.append('file', file);
        
        const res = await fetch(window.wp.apiSettings.root + 'wp/v2/ssp/media/upload', {
          method: 'POST',
          headers: {
            'X-WP-Nonce': window.wp.apiSettings.nonce,
          },
          body: formData,
        });

        if (!res.ok) throw new Error('Upload failed');
      }

      notify('Files uploaded successfully!', 'success');
      setPage(1);
      loadMedia();
    } catch (error) {
      notify('Upload failed', 'error');
    } finally {
      setUploading(false);
    }
  };

  const handleLinkProduct = async (mediaId, productId) => {
    try {
      await fetchApi(`/media/${mediaId}`, {
        method: 'PUT',
        data: { product_id: productId },
      });
      notify('Product linked!', 'success');
      loadMedia();
      setSelectedMedia(null);
    } catch (error) {
      notify('Failed to link product', 'error');
    }
  };

  const handleDeleteMedia = async (mediaId) => {
    if (!window.confirm('Delete this media? This action cannot be undone.')) {
      return;
    }

    try {
      await fetchApi(`/media/${mediaId}`, { method: 'DELETE' });
      notify('Media deleted!', 'success');
      setPage(1);
      loadMedia();
    } catch (error) {
      notify('Failed to delete media', 'error');
    }
  };

  const totalPages = Math.ceil(total / perPage);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-4xl font-bold text-gray-900">Media Gallery</h1>
        <p className="text-gray-600 mt-2">Manage your images and Google Flow integration</p>
      </div>

      {/* Tabs */}
      <div className="flex gap-4 border-b border-gray-200">
        <button
          onClick={() => setTab('gallery')}
          className={`px-4 py-2 font-medium border-b-2 transition-colors ${
            tab === 'gallery'
              ? 'border-blue-600 text-blue-600'
              : 'border-transparent text-gray-600 hover:text-gray-900'
          }`}
        >
          📁 My Gallery
        </button>
        <button
          onClick={() => setTab('google-flow')}
          className={`px-4 py-2 font-medium border-b-2 transition-colors ${
            tab === 'google-flow'
              ? 'border-blue-600 text-blue-600'
              : 'border-transparent text-gray-600 hover:text-gray-900'
          }`}
        >
          🎨 Google Flow
        </button>
      </div>

      {/* Gallery Tab */}
      {tab === 'gallery' && (
        <div className="space-y-6">
          {/* Upload Zone */}
          <Card className="border-2 border-dashed border-gray-300 p-8">
            <div className="text-center">
              <div className="text-4xl mb-3">📤</div>
              <h3 className="font-bold text-lg text-gray-900 mb-2">
                Drop images here or click to upload
              </h3>
              <p className="text-gray-600 mb-4">
                Supported formats: JPG, PNG, WebP • Max size: 10MB
              </p>
              <label className="inline-block">
                <button className="px-6 py-2 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition-colors">
                  Choose Files
                </button>
                <input
                  type="file"
                  multiple
                  accept="image/jpeg,image/png,image/webp"
                  onChange={(e) => handleUpload(Array.from(e.target.files))}
                  disabled={uploading}
                  className="hidden"
                />
              </label>
              {uploading && (
                <div className="mt-4">
                  <LoadingSpinner size="sm" />
                  <p className="text-gray-600 mt-2">Uploading...</p>
                </div>
              )}
            </div>
          </Card>

          {/* Media Grid */}
          {loading && page === 1 ? (
            <div className="flex justify-center items-center py-12">
              <LoadingSpinner size="lg" />
            </div>
          ) : media.length === 0 ? (
            <div className="text-center py-12">
              <p className="text-gray-500 text-lg">No media uploaded yet</p>
            </div>
          ) : (
            <>
              <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                {media.map((item) => (
                  <MediaTile
                    key={item.id}
                    media={item}
                    products={products}
                    onSelect={setSelectedMedia}
                    onDelete={handleDeleteMedia}
                    selected={selectedMedia?.id === item.id}
                  />
                ))}
              </div>

              {/* Pagination */}
              {totalPages > 1 && (
                <div className="flex justify-center gap-2 mt-8">
                  <button
                    onClick={() => setPage(Math.max(1, page - 1))}
                    disabled={page === 1}
                    className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                  >
                    ← Previous
                  </button>
                  <span className="px-4 py-2 text-gray-700">
                    Page {page} of {totalPages}
                  </span>
                  <button
                    onClick={() => setPage(Math.min(totalPages, page + 1))}
                    disabled={page === totalPages}
                    className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                  >
                    Next →
                  </button>
                </div>
              )}
            </>
          )}

          {/* Media Detail Sidebar */}
          {selectedMedia && (
            <MediaDetailPanel
              media={selectedMedia}
              products={products}
              onLinkProduct={handleLinkProduct}
              onClose={() => setSelectedMedia(null)}
            />
          )}
        </div>
      )}

      {/* Google Flow Tab */}
      {tab === 'google-flow' && (
        <Card>
          <h2 className="text-2xl font-bold text-gray-900 mb-4">Google Flow Integration</h2>
          <p className="text-gray-600 mb-6">
            Generate professional images using Google Flow's NanoBanana & NanoBanana Pro
          </p>
          <GoogleFlowEmbed onImagesGenerated={() => {
            setTab('gallery');
            loadMedia();
          }} />
        </Card>
      )}
    </div>
  );
}

/**
 * MediaTile Component
 */
function MediaTile({ media, products, onSelect, onDelete, selected }) {
  const linkedProduct = products.find(p => p.id === media.product_id);

  return (
    <div
      onClick={() => onSelect(media)}
      className={`relative group cursor-pointer rounded-lg overflow-hidden transition-all ${
        selected ? 'ring-2 ring-blue-500' : 'hover:shadow-lg'
      }`}
    >
      {/* Image */}
      <img
        src={media.thumbnail_url || media.url}
        alt={media.title}
        className="w-full aspect-square object-cover bg-gray-100"
      />

      {/* Overlay */}
      <div className="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all flex items-center justify-center">
        <div className="opacity-0 group-hover:opacity-100 transition-opacity">
          <Button
            onClick={(e) => {
              e.stopPropagation();
              onDelete(media.id);
            }}
            variant="danger"
            size="sm"
          >
            🗑️ Delete
          </Button>
        </div>
      </div>

      {/* Product Badge */}
      {linkedProduct && (
        <div className="absolute top-2 left-2 bg-green-500 text-white text-xs font-bold px-2 py-1 rounded">
          ✓ Linked
        </div>
      )}

      {/* Title */}
      <div className="p-2 bg-gray-50 border-t border-gray-200 min-h-12">
        <p className="text-xs font-medium text-gray-900 line-clamp-2">
          {media.title || 'Untitled'}
        </p>
      </div>
    </div>
  );
}

/**
 * MediaDetailPanel Component
 */
function MediaDetailPanel({ media, products, onLinkProduct, onClose }) {
  const [selectedProduct, setSelectedProduct] = useState(media.product_id || '');

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-end z-40">
      <Card className="w-full md:w-96 rounded-t-lg md:rounded-lg">
        <div className="flex justify-between items-start mb-4">
          <h3 className="text-xl font-bold text-gray-900">Media Details</h3>
          <button
            onClick={onClose}
            className="text-2xl text-gray-500 hover:text-gray-700"
          >
            ✕
          </button>
        </div>

        {/* Preview */}
        <img
          src={media.url}
          alt={media.title}
          className="w-full rounded-lg mb-4"
        />

        {/* Details */}
        <div className="space-y-4 mb-6">
          <div>
            <label className="block text-sm font-medium text-gray-700">Title</label>
            <p className="mt-1 text-gray-900">{media.title || 'Untitled'}</p>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700">Dimensions</label>
            <p className="mt-1 text-gray-900">{media.dimensions}</p>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Link to Product
            </label>
            <select
              value={selectedProduct}
              onChange={(e) => {
                setSelectedProduct(e.target.value);
                if (e.target.value) {
                  onLinkProduct(media.id, parseInt(e.target.value));
                }
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg"
            >
              <option value="">None</option>
              {products.map(p => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
        </div>

        <button
          onClick={onClose}
          className="w-full px-4 py-2 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50"
        >
          Done
        </button>
      </Card>
    </div>
  );
}
