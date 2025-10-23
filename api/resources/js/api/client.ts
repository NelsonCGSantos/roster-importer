import axios from 'axios';

export const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
    },
});

export function setAuthToken(token: string | null) {
    if (token) {
        api.defaults.headers.common.Authorization = `Bearer ${token}`;
    } else {
        delete api.defaults.headers.common.Authorization;
    }
}

api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (import.meta.env.DEV) {
            console.error('API error', error);
        }

        return Promise.reject(error);
    }
);
