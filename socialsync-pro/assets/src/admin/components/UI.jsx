import React from 'react';

/**
 * Card Component
 */
export function Card({ children, className = '', onClick = null }) {
  return (
    <div
      className={`bg-white rounded-lg shadow-md p-6 transition hover:shadow-lg ${
        onClick ? 'cursor-pointer' : ''
      } ${className}`}
      onClick={onClick}
    >
      {children}
    </div>
  );
}

/**
 * Stat Card Component
 */
export function StatCard({ label, value, icon = null, color = 'blue' }) {
  const colorClasses = {
    blue: 'bg-blue-100 text-blue-600',
    green: 'bg-green-100 text-green-600',
    red: 'bg-red-100 text-red-600',
    yellow: 'bg-yellow-100 text-yellow-600',
  };

  return (
    <Card className="text-center">
      {icon && (
        <div className={`w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-4 ${colorClasses[color]}`}>
          {icon}
        </div>
      )}
      <p className="text-gray-600 text-sm font-medium">{label}</p>
      <p className="text-3xl font-bold text-gray-900 mt-2">{value}</p>
    </Card>
  );
}

/**
 * Badge Component
 */
export function Badge({ children, variant = 'default', size = 'md' }) {
  const variantClasses = {
    default: 'bg-gray-200 text-gray-800',
    primary: 'bg-blue-200 text-blue-800',
    success: 'bg-green-200 text-green-800',
    danger: 'bg-red-200 text-red-800',
    warning: 'bg-yellow-200 text-yellow-800',
  };

  const sizeClasses = {
    sm: 'px-2 py-1 text-xs',
    md: 'px-3 py-1 text-sm',
    lg: 'px-4 py-2 text-base',
  };

  return (
    <span className={`inline-block rounded-full font-medium ${variantClasses[variant]} ${sizeClasses[size]}`}>
      {children}
    </span>
  );
}

/**
 * Button Component
 */
export function Button({ children, onClick, variant = 'primary', size = 'md', disabled = false, loading = false }) {
  const variantClasses = {
    primary: 'bg-blue-600 text-white hover:bg-blue-700',
    secondary: 'bg-gray-600 text-white hover:bg-gray-700',
    danger: 'bg-red-600 text-white hover:bg-red-700',
    outline: 'border-2 border-blue-600 text-blue-600 hover:bg-blue-50',
  };

  const sizeClasses = {
    sm: 'px-3 py-1 text-sm',
    md: 'px-4 py-2 text-base',
    lg: 'px-6 py-3 text-lg',
  };

  return (
    <button
      onClick={onClick}
      disabled={disabled || loading}
      className={`font-medium rounded transition ${variantClasses[variant]} ${sizeClasses[size]} ${
        disabled || loading ? 'opacity-50 cursor-not-allowed' : ''
      }`}
    >
      {loading ? 'Loading...' : children}
    </button>
  );
}
