import React, { useState, useEffect } from 'react';
import { Card, Badge, Button } from '../components/UI';
import { LoadingSpinner } from '../components/Loading';
import { PlatformBadge } from '../components/Badges';
import { useApi } from '../context/ApiContext';
import { useNotification } from '../context/NotificationContext';

/**
 * Catalog Page
 * View WooCommerce products and manage product picking
 */
export function Catalog() {
  const { fetchApi } = useApi();
  const { notify } = useNotification();
  const [products, setProducts] = useState([]);
  const [dailyPicks, setDailyPicks] = useState([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedProduct, setSelectedProduct] = useState(null);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [filter, setFilter] = useState('all'); // all, featured, bestsellers

  useEffect(() => {
    loadCatalog();
    loadDailyPicks();
  }, [page, filter, searchTerm]);

  const loadCatalog = async () => {
    try {
      setLoading(true);
      const params = new URLSearchParams({
        page,
        per_page: 24,
        filter,
        search: searchTerm,
      });
      const res = await fetchApi(`/catalog?${params}`);
      if (res) {
        setProducts(res.data || []);
        setTotalPages(res.total_pages || 1);
      }
    } catch (error) {
      notify('Failed to load catalog', 'error');
    } finally {
      setLoading(false);
    }
  };

  const loadDailyPicks = async () => {
    try {
      const res = await fetchApi('/catalog/picks');
      if (res) {
        setDailyPicks(res);
      }
    } catch (error) {
      console.error('Failed to load daily picks:', error);
    }
  };

  const handleSyncNow = async () => {
    try {
      notify('Syncing catalog...', 'info');
      await fetchApi('/catalog/sync', { method: 'POST' });
      notify('Sync complete!', 'success');
      loadCatalog();
    } catch (error) {
      notify('Sync failed', 'error');
    }
  };

  const ProductCard = ({ product }) => (
    <Card
      className="cursor-pointer transform hover:scale-105 transition"
      onClick={() => setSelectedProduct(product)}
    >
      <div className="space-y-3">
        <div className="h-40 bg-gray-200 rounded overflow-hidden">
          {product.image_url && (
            <img
              src={product.image_url}
              alt={product.name}
              className="w-full h-full object-cover"
            />
          )}
        </div>
        <div>
          <h3 className="font-bold text-gray-900 truncate">{product.name}</h3>
          <p className="text-2xl font-bold text-green-600 mt-1">
            ${parseFloat(product.price).toFixed(2)}
          </p>
        </div>
        <div className="flex gap-2 flex-wrap">
          {product.is_featured && <Badge variant="primary">Featured</Badge>}
          {product.total_sales > 10 && <Badge variant="success">Best Seller</Badge>}
        </div>
        <p className="text-gray-500 text-xs">
          {product.total_sales} sales
        </p>
      </div>
    </Card>
  );

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-3xl font-bold text-gray-900">Product Catalog</h1>
          <p className="text-gray-600 mt-1">Manage WooCommerce product sync</p>
        </div>
        <Button onClick={handleSyncNow} variant="primary">
          🔄 Sync Now
        </Button>
      </div>

      {/* Daily Picks */}
      {dailyPicks.length > 0 && (
        <Card className="bg-yellow-50 border-2 border-yellow-200">
          <h2 className="text-lg font-bold text-yellow-900 mb-4">⭐ Today's Picks</h2>
          <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
            {dailyPicks.map((product) => (
              <div key={product.id} className="bg-white rounded p-3 text-center">
                <div className="h-20 bg-gray-200 rounded mb-2 overflow-hidden">
                  {product.image_url && (
                    <img
                      src={product.image_url}
                      alt={product.name}
                      className="w-full h-full object-cover"
                    />
                  )}
                </div>
                <p className="font-bold text-sm text-gray-900 truncate">
                  {product.name}
                </p>
                <p className="text-xs text-gray-600 mt-1">
                  {product.pick_reason === 'featured' ? '⭐ Featured' : '🔥 Best Seller'}
                </p>
              </div>
            ))}
          </div>
        </Card>
      )}

      {/* Search & Filter */}
      <Card>
        <div className="flex flex-col md:flex-row gap-4">
          <input
            type="text"
            placeholder="Search products..."
            value={searchTerm}
            onChange={(e) => {
              setSearchTerm(e.target.value);
              setPage(1);
            }}
            className="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <select
            value={filter}
            onChange={(e) => {
              setFilter(e.target.value);
              setPage(1);
            }}
            className="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            <option value="all">All Products</option>
            <option value="featured">Featured Only</option>
            <option value="bestsellers">Best Sellers Only</option>
          </select>
        </div>
      </Card>

      {/* Product Grid */}
      {loading ? (
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-6">
          {products.map((product) => (
            <ProductCard key={product.id} product={product} />
          ))}
        </div>
      )}

      {/* Pagination */}
      {totalPages > 1 && (
        <Card className="flex justify-center gap-2">
          <Button
            onClick={() => setPage(Math.max(1, page - 1))}
            disabled={page === 1}
            variant="outline"
          >
            ← Previous
          </Button>
          <span className="py-2 px-4">
            Page {page} of {totalPages}
          </span>
          <Button
            onClick={() => setPage(Math.min(totalPages, page + 1))}
            disabled={page === totalPages}
            variant="outline"
          >
            Next →
          </Button>
        </Card>
      )}

      {/* Selected Product Modal */}
      {selectedProduct && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <Card className="max-w-2xl w-full mx-4">
            <button
              onClick={() => setSelectedProduct(null)}
              className="float-right text-2xl text-gray-500 hover:text-gray-700"
            >
              ✕
            </button>
            <div className="grid grid-cols-2 gap-6">
              <div>
                <div className="h-64 bg-gray-200 rounded overflow-hidden mb-4">
                  {selectedProduct.image_url && (
                    <img
                      src={selectedProduct.image_url}
                      alt={selectedProduct.name}
                      className="w-full h-full object-cover"
                    />
                  )}
                </div>
              </div>
              <div className="space-y-4">
                <div>
                  <h2 className="text-2xl font-bold text-gray-900">
                    {selectedProduct.name}
                  </h2>
                  <p className="text-3xl font-bold text-green-600 mt-2">
                    ${parseFloat(selectedProduct.price).toFixed(2)}
                  </p>
                </div>
                <div>
                  <h3 className="font-bold text-gray-900 mb-2">Status</h3>
                  <div className="flex gap-2 flex-wrap">
                    {selectedProduct.is_featured && (
                      <Badge variant="primary">Featured</Badge>
                    )}
                    {selectedProduct.total_sales > 10 && (
                      <Badge variant="success">Best Seller</Badge>
                    )}
                  </div>
                </div>
                <div>
                  <p className="text-gray-600">
                    <strong>Total Sales:</strong> {selectedProduct.total_sales}
                  </p>
                  <p className="text-gray-600">
                    <strong>Last Synced:</strong>{' '}
                    {new Date(selectedProduct.synced_at).toLocaleString()}
                  </p>
                </div>
                <Button
                  onClick={() => {
                    window.location.hash = '/composer';
                    setSelectedProduct(null);
                  }}
                  variant="primary"
                >
                  Create Post from This Product
                </Button>
              </div>
            </div>
          </Card>
        </div>
      )}
    </div>
  );
}
