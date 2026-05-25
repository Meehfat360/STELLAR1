import React, { useState, useEffect } from 'react';
import { Card, StatCard, Badge, Button } from '../components/UI';
import { PlatformBadge, PostStatusBadge } from '../components/Badges';
import { LoadingSpinner } from '../components/Loading';
import { useApi } from '../context/ApiContext';
import { useNotification } from '../context/NotificationContext';

/**
 * Dashboard Page
 * Main hub showing stats, activity feed, and quick actions
 */
export function Dashboard() {
  const { fetchApi } = useApi();
  const { notify } = useNotification();
  const [stats, setStats] = useState(null);
  const [activity, setActivity] = useState([]);
  const [loading, setLoading] = useState(true);
  const [platformStatus, setPlatformStatus] = useState({
    facebook: false,
    instagram: false,
    pinterest: false,
  });

  useEffect(() => {
    loadDashboardData();
  }, []);

  const loadDashboardData = async () => {
    try {
      setLoading(true);
      const [statsRes, activityRes, statusRes] = await Promise.all([
        fetchApi('/analytics'),
        fetchApi('/logs?limit=20&level=info'),
        fetchApi('/settings'),
      ]);

      if (statsRes) setStats(statsRes);
      if (activityRes) setActivity(activityRes);
      
      if (statusRes) {
        setPlatformStatus({
          facebook: !!statusRes.fb_page_access_token,
          instagram: !!statusRes.instagram_access_token,
          pinterest: !!statusRes.pinterest_access_token,
        });
      }
    } catch (error) {
      notify('Failed to load dashboard data', 'error');
    } finally {
      setLoading(false);
    }
  };

  const handleSyncNow = async () => {
    try {
      notify('Syncing catalog...', 'info');
      await fetchApi('/catalog/sync', { method: 'POST' });
      notify('Catalog synced successfully!', 'success');
      loadDashboardData();
    } catch (error) {
      notify('Sync failed', 'error');
    }
  };

  const handleGeneratePosts = async () => {
    try {
      notify('Generating daily posts...', 'info');
      await fetchApi('/ai/generate', {
        method: 'POST',
        data: { auto_workflow: true },
      });
      notify('Posts generated successfully!', 'success');
      loadDashboardData();
    } catch (error) {
      notify('Generation failed', 'error');
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-screen">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div className="space-y-8">
      {/* Header */}
      <div>
        <h1 className="text-4xl font-bold text-gray-900">Dashboard</h1>
        <p className="text-gray-600 mt-2">Welcome to SocialSync Pro</p>
      </div>

      {/* Stats Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <StatCard
          label="Products Synced"
          value={stats?.total_products || 0}
          color="blue"
          icon="📦"
        />
        <StatCard
          label="Posts Published"
          value={stats?.posts_published_today || 0}
          color="green"
          icon="✅"
        />
        <StatCard
          label="Scheduled Posts"
          value={stats?.scheduled_posts || 0}
          color="yellow"
          icon="📅"
        />
        <StatCard
          label="AI Requests Used"
          value={`${stats?.ai_requests_today || 0}/20`}
          color="red"
          icon="🤖"
        />
      </div>

      {/* Platform Status */}
      <Card>
        <h2 className="text-xl font-bold text-gray-900 mb-4">Platform Status</h2>
        <div className="grid grid-cols-3 gap-4">
          <PlatformBadge platform="facebook" connected={platformStatus.facebook} />
          <PlatformBadge platform="instagram" connected={platformStatus.instagram} />
          <PlatformBadge platform="pinterest" connected={platformStatus.pinterest} />
        </div>
      </Card>

      {/* Quick Actions */}
      <Card>
        <h2 className="text-xl font-bold text-gray-900 mb-4">Quick Actions</h2>
        <div className="flex flex-wrap gap-4">
          <Button onClick={handleSyncNow} variant="primary">
            🔄 Sync Catalog Now
          </Button>
          <Button onClick={handleGeneratePosts} variant="primary">
            ✨ Generate Today's Posts
          </Button>
          <Button onClick={() => window.location.hash = '/scheduler'} variant="secondary">
            📊 View Queue
          </Button>
          <Button onClick={() => window.location.hash = '/settings'} variant="outline">
            ⚙️ Settings
          </Button>
        </div>
      </Card>

      {/* Recent Activity */}
      <Card>
        <h2 className="text-xl font-bold text-gray-900 mb-4">Recent Activity</h2>
        <div className="space-y-3">
          {activity.length === 0 ? (
            <p className="text-gray-500 text-center py-8">No activity yet</p>
          ) : (
            activity.map((log, idx) => (
              <div key={idx} className="flex items-start justify-between pb-3 border-b last:border-b-0">
                <div className="flex-1">
                  <p className="text-gray-900 font-medium">{log.message}</p>
                  <p className="text-gray-500 text-sm mt-1">
                    {new Date(log.created_at).toLocaleString()}
                  </p>
                </div>
                <Badge
                  variant={
                    log.level === 'error' ? 'danger' : log.level === 'warning' ? 'warning' : 'success'
                  }
                  size="sm"
                >
                  {log.level}
                </Badge>
              </div>
            ))
          )}
        </div>
      </Card>

      {/* Performance Tips */}
      <Card className="bg-blue-50 border-2 border-blue-200">
        <h3 className="text-lg font-bold text-blue-900 mb-3">💡 Performance Tips</h3>
        <ul className="space-y-2 text-blue-800 text-sm">
          <li>✓ Run catalog sync daily at off-peak hours</li>
          <li>✓ Use featured products for higher engagement</li>
          <li>✓ Generate posts 2-3 days in advance for better scheduling</li>
          <li>✓ Monitor platform performance in Analytics</li>
        </ul>
      </Card>
    </div>
  );
}
