export interface LoginResponse {
  status: string;
  message: string;
  data: {
    user: {
      id: number;
      username: string;
      email: string;
      full_name: string;
      role: string;
      role_id: number;
      organization?: {
        id: number;
        name: string;
      };
    };
    token: string;
  };
}

export interface ForgotPasswordResponse {
  status: string;
  message: string;
}

export interface UserData {
  id: number;
  username: string;
  email: string;
  full_name: string;
  role: string;
  role_id: number;
  organization?: {
    id: number;
    name: string;
  };
}
